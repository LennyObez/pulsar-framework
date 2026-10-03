# ADR-0078: A stream that cannot say it finished has not finished

## Status

Accepted. Adds one method to a published `#[Api]` interface — a recorded RC-phase break — plus
seventeen new types under `Pulsar\AI\Streaming\` (seven `#[Api]`, ten `#[Internal]`), one new
`#[Api]` exception type, and streaming implementations on all three providers.

Applies
[ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md): every
claim below was watched failing against unmutated code before it was believed. Applies
[ADR-0045](0045-a-control-status-is-observed-not-written.md) and
[ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md): a finish reason and
a token count are facts produced by the provider that measured them, never literals written by
the code that wanted them. Constrained by
[ADR-0071](0071-a-fiber-keyed-map-is-not-concurrency.md): no fibers.

## Context

`Pulsar\AI\AiClientInterface` exposed `chat()`, `complete()`, `embed()`, `structuredOutput()`
and `providerName()`. All four returned a finished `AiResponse`. There was no streaming
anywhere in `src/AI/`, and no seam through which anyone could add it — `HttpClientInterface`
returns an `HttpResponse` whose body has already been read to the end, so a caller who needed
tokens as they were generated had to leave the framework entirely and talk to the provider
themselves. A framework that is not on the path where the work happens is not being used for
that work.

The urgency was not the absence. It was the attribute. `AiClientInterface` carries
`#[Api(since: '1.0.0')]`. Adding a method to a published interface after the tag breaks every
implementer; adding it during the release candidate costs one recorded break in an RC, and
adding it afterwards costs a major version. There is no third option where it is free.

### The thing a stream can do that a response cannot

An `AiResponse` either exists or it does not. A stream has a third state: the connection
returned `200 OK`, the headers were written, half the body arrived, and the socket died. The
bytes that arrived are indistinguishable from the beginning of a complete answer. Nothing in
the transport can tell the two apart — that is what "the headers already went out" means.

For a framework aimed at banking, healthcare and legal software, "a truncated answer that looks
finished" is the whole risk. So is a tool call assembled from half its JSON arguments: the model
asked to transfer `{"amount": 100, "to"` and the connection stopped there. Decoding that prefix
leniently is how a tool gets invoked with parameters nobody chose.

And a streamed call still has to be billable and auditable. `AiResponse::$inputTokens` and
`$outputTokens` are what budgets charge and what audit records carry. A streaming path that
could not produce them would quietly exempt every streamed call from both.

## Decision

### One method, returning a named type

```php
public function streamChat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiStream;
```

One method, so the break is one break. `streamChat` rather than `stream` because the interface
already names its operations after what they do, and a caller who wants to stream a single
prompt writes `streamChat([ChatMessage::user($p)])`.

It returns `AiStream` rather than a bare `iterable<AiStreamDelta>` for two reasons. A bare
iterable has nowhere to put the accumulated response, and a `Generator`'s `getReturn()` is a
mechanism people forget. And decorators — the audit decorator, the budget decorator — need a
type they can both consume and construct, spelled the same way by everyone who writes one. The
documented decorator shape is in `AiStream`'s own docblock, and a test asserts that it composes.

`AiStream` is `final readonly` and holds **nothing but the generator**. An earlier draft cached
`$response` and `$started` on the object; the class-shape gate was right to refuse it, and the
reason it gave is the design argument: a final mutable class in an `#[Api]` seam is a dependency
nobody can substitute. Removing the cache removed the objection and improved the class, because
whether a stream has finished and what it finished with are facts the generator already carries,
and a second copy on the object could only ever disagree with it. `response()` therefore drains
the generator and reads its return value rather than remembering anything.

Two consequences follow from having no state, and both are better than what the cache bought:

- **Stopping early loses the deltas, not the answer.** `response()` after a `break` resumes the
  generator, finishes it, and returns the real complete response. The earlier draft refused,
  which was a refusal with nothing behind it — the answer was complete and available.
- **A stream read to its end cannot be replayed**, because the engine refuses to traverse a
  closed generator. That is the guard, stated by the runtime rather than duplicated by us.

### A delta is four things, not a string

`AiStreamDelta` carries exactly one of: a text fragment, a tool call fragment, token usage, or
the terminal event. Its constructor is private and its four named constructors are the only way
to build one, so a text delta cannot be given a finish reason.

Collapsing this to text was rejected. Tool calling already exists in this module; a stream that
cannot carry a tool call is half a feature. Token usage arrives at a different moment from the
finish reason on every provider — Anthropic opens with the prompt count and closes with the
output count, OpenAI sends both on a trailing chunk with no choices, Ollama sends both on the
`done` object — so folding usage into the terminal event would lose one of the two numbers.
`AiTokenUsage` therefore has two _nullable_ fields: null means "this event said nothing about
that number", which is not the same fact as zero.

Tool call arguments stay **text** until the stream ends. `ToolCallDelta::$argumentsFragment` is
raw JSON to be appended, and `AiStreamAccumulator` decodes only once, at the end. Ollama does not
fragment its arguments; its parser re-encodes the finished object into one fragment so that all
three providers fold identically instead of the accumulator branching on which one fragments.

### The terminal event is observed, never assumed

`AiStreamAccumulator::finish()` refuses — `AiStreamException::truncated()` — unless a `Finish`
delta was actually seen. Nothing writes `'stop'` as a default. Consequently:

- Anthropic's stream is complete only after `message_delta` carries a `stop_reason`.
- OpenAI's `data: [DONE]` is explicitly **not** treated as terminal. It says the connection
  finished, not that the model did. Only `choices[0].finish_reason` completes a stream.
- Ollama's stream is complete only after an object with `done: true`.

Arguments that do not reassemble into a JSON object fail the whole stream
(`AiStreamException::malformedToolCall()`) rather than yielding a `ToolCall` with fewer
parameters than the model asked for.

There is no way to hold a truncated answer in an `AiResponse`-shaped variable:
`AiStream::response()` throws in every case except a stream that reached its terminal event, and
a stream that already failed keeps throwing when asked again.
`AiStreamException::$partialContent` carries the text that did
arrive, deliberately as a plain string — it has no token counts, so nothing downstream will bill
it, and it cannot be mistaken for a response.

### The timeout is idle, not total

`AiRequestOptions::$timeoutSeconds` becomes the **maximum silence between chunks** for a stream.
Total generation time is not capped. Capping it would kill exactly the long answers streaming
exists to serve; a socket that has said nothing for the configured window is the actual failure.

### Ordinary generators over a blocking read

No fibers. `src/Runtime` refuses `fiber_concurrency > 1` and ADR-0071 records why a fiber-keyed
construct is not a scheduling primitive here. A stream does not need concurrency — it needs to
not buffer — and `fread()` in a generator does exactly that.

### The framing lives once, the provider quirks live per provider

`SseReader` frames `text/event-stream` for the two providers that speak it; `NdJsonReader`
frames Ollama's newline-delimited JSON. Transport chunks split wherever TCP split them,
including between the `\r` and the `\n` of a CRLF, so both readers buffer across chunks and the
provider parsers never see bytes. Three thin parsers translate each provider's event shapes into
deltas. An `if`/`else` per provider inside one method was rejected: the three wire formats have
nothing in common except that they arrive in pieces.

`SseReader::events()` is static, the way `Support\Coerce` is. It holds no state, has no second
implementation and nothing to configure, so injecting it would have made each parser depend on a
strategy that is really a pure function — which is exactly what the class-shape gate reported
when it was a promoted property.

### The socket is one line behind a seam

`StreamTransportInterface` is what the providers stream through and what a test replaces.
`StreamContextTransport` holds the policy — which statuses are acceptable, how long a silence may
last, that redirects are refused so a `302` cannot escape the caller's SSRF check — and
`HttpStreamOpenerInterface` holds the single `fopen()` call that cannot be proved without a
network. Everything else is driven in tests against real sockets from `stream_socket_pair()`.

## Consequences

### The break

`AiClientInterface` gains a method. Every implementer outside this repository must add
`streamChat()`. There is no default implementation and no trait, because a client that silently
"streams" by yielding one delta containing a whole buffered response would be a lie that type
checks. Recorded in `CHANGELOG.md` and `docs/upgrade.md`.

Inside this repository the cost was four anonymous test doubles, each of which now declares that
it does not stream.

### Two constructor parameters that are not about streaming

`OllamaProvider` gained an optional `HttpClientInterface`, matching the other two providers. It
had none, so its unstreamed response parsing could not be exercised at all without a live
Ollama — and this change added code to that path.

That code is the second consequence: the unstreamed Ollama path was **discarding
`message.tool_calls` outright**, and `/api/chat` was never sent a `tools` field, so Ollama tool
calling did not work at all. Implementing it only for the stream would have left the two paths
disagreeing about identical bytes, so both were fixed together. Ollama assigns no call id; the
id stays empty rather than being invented.

### What is still not covered

The `fopen()` call in `StreamContextOpener` is not exercised by an automated check — nothing in
this suite opens a network connection, by design. The policy around it is covered; the syscall
is not. This is stated rather than hidden.

### Evidence

Thirteen mutations were applied one at a time to unmutated code and each was watched failing,
then reverted and the file hash verified:

| Mutation                                                | Check that caught it                                  |
| ------------------------------------------------------- | ----------------------------------------------------- |
| `finish()` writes a literal `'stop'`                    | truncation refused, all three providers + kernel path |
| the provider buffers every delta before yielding        | delta interleaving, unit and through the kernel       |
| the accumulator drops argument fragments                | tool call reassembly                                  |
| a usage event overwrites the half it did not report     | input tokens survive to the browser                   |
| `[DONE]` treated as terminal                            | a stream with no `finish_reason` is still refused     |
| the NDJSON reader discards an unterminated final object | Ollama's `done` object without its newline            |
| the unstreamed Ollama path drops tool calls             | streamed and unstreamed parses agree                  |
| malformed tool arguments decoded leniently              | a half-written `transfer` call fails the stream       |
| `StreamedResponse::getBody()` consumes its source       | a body-reading middleware does not empty the stream   |
| the transport follows redirects                         | the request refuses `follow_location`                 |
| a stalled socket read as a clean end of body            | the idle window is waited out and then refused        |
| a non-2xx status read as the start of a stream          | HTTP 429 refuses with its error body                  |
| the SSE reader waits for the whole body                 | delta interleaving, every provider                    |

`composer class-shape` reports **no new finding** from this change: regenerating its baseline
against the working tree adds nothing under `src/AI/`.
