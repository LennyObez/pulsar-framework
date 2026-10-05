# Streaming AI responses

`Pulsar\AI\AiClientInterface::streamChat()` reads a model's answer as it is generated, instead
of waiting for the whole thing. Every provider Pulsar ships supports it: Anthropic and OpenAI
over server-sent events, Ollama over newline-delimited JSON.

The design decision behind it is recorded in
[ADR-0078](adr/0078-a-stream-that-cannot-say-it-finished-has-not-finished.md).

## The shape

```php
public function streamChat(
    array $messages,
    AiRequestOptions $options = new AiRequestOptions(),
): AiStream;
```

An `AiStream` is iterated for the pieces and then asked for the whole:

```php
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Streaming\AiStreamEventType;

$stream = $client->streamChat([ChatMessage::user('Summarise this claim.')]);

foreach ($stream as $delta) {
    if ($delta->type === AiStreamEventType::Text) {
        echo $delta->text;
    }
}

$response = $stream->response();   // the same AiResponse chat() would have returned
echo $response->totalTokens();
```

If you only want the transport to stream and do not care about the pieces, call `response()`
without iterating — it drains the stream for you. The same call finishes a stream whose deltas
you stopped reading: the deltas are gone, but the answer is the real one.

A stream is single-pass. Reading one to its end and then iterating it again is refused.

## What a delta carries

A stream is not a sequence of strings. `AiStreamDelta::$type` says which of four facts a delta
carries, and only the matching field is populated.

| `AiStreamEventType` | Field           | Meaning                                                                  |
| ------------------- | --------------- | ------------------------------------------------------------------------ |
| `Text`              | `$text`         | A fragment of assistant text.                                            |
| `ToolCall`          | `$toolCall`     | Part of a tool call: its id, its name, or a slice of its JSON arguments. |
| `Usage`             | `$usage`        | Token counts, as and when the provider reports them.                     |
| `Finish`            | `$finishReason` | The terminal event. Exactly one of these completes a stream.             |

`AiTokenUsage::$inputTokens` and `$outputTokens` are both nullable. A null means _this event said
nothing about that number_ — which is not the same fact as zero. Providers report the two halves
at different moments, and `AiStream::response()` folds whichever half each event actually carried.

## Tool calls

Tool calls survive streaming. No provider sends one whole: Anthropic announces the id and name
and then streams the arguments as partial JSON, OpenAI does the same over indexed `tool_calls`
entries, Ollama sends the arguments as one finished object. All three arrive as `ToolCallDelta`
fragments grouped by `$index`, and the accumulator reassembles them.

Arguments stay text until the stream ends, and are decoded exactly once. A tool call whose
arguments never became a JSON object fails the whole stream rather than producing a `ToolCall`
with fewer parameters than the model asked for.

```php
$stream = $client->streamChat($messages, new AiRequestOptions(tools: [$getBalance]));

foreach ($stream as $delta) {
    // render $delta->text as it arrives
}

foreach ($stream->response()->toolCalls as $call) {
    $result = $dispatcher->run($call->name, $call->arguments);
}
```

Ollama assigns no call id, so `ToolCall::$id` is empty for that provider. Nothing invents one.

## A stream that stops early is refused, not returned

This is the part worth reading twice.

A model call over HTTP can return `200 OK`, write its headers, deliver half the body and then
lose the connection. The bytes that arrived look exactly like the beginning of a complete answer.

Pulsar refuses to let that be mistaken for one. `AiStream::response()` returns an `AiResponse`
**only** when the provider's own terminal event was observed. In every other case it throws
`Pulsar\AI\Exception\AiStreamException`:

- the body ended without a terminal event — `truncated()`
- the provider sent an error event mid-stream — `providerError()`
- nothing arrived for `timeoutSeconds` — `stalled()`
- the connection could not be opened, or died mid-body — `transportFailure()`
- the provider refused with a non-2xx status — `httpError()`
- tool arguments did not reassemble into an object — `malformedToolCall()`
- the stream already failed and is asked for a response again — `didNotComplete()`

OpenAI's `data: [DONE]` is deliberately **not** treated as terminal. It reports that the
connection finished, not that the model did; only a `finish_reason` completes an OpenAI stream.

`AiStreamException::$partialContent` carries the text that did arrive, for callers that want to
show it. It is a plain string on purpose: it has no token counts, nothing downstream will bill
it, and it cannot be handed anywhere an `AiResponse` is expected.

## Timeouts

For a stream, `AiRequestOptions::$timeoutSeconds` is the **maximum silence between chunks**, not
a deadline for the whole response. A long answer is not a failure; a socket that has said nothing
for the configured window is. Total generation time is deliberately uncapped.

```php
// Fail if the model goes quiet for 30 seconds. It may take as long as it likes otherwise.
$stream = $client->streamChat($messages, new AiRequestOptions(timeoutSeconds: 30));
```

## Streaming to a browser

`StreamedResponse` carries a stream to the client without buffering it. Nothing on the path
between the provider socket and the emitter holds the whole answer.

```php
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\AiStreamEventType;
use Pulsar\Http\Response\StreamedResponse;

$router->get('/chat', function () use ($client): StreamedResponse {
    $stream = $client->streamChat([ChatMessage::user('Say hello')]);

    return new StreamedResponse(
        (function () use ($stream): Generator {
            try {
                foreach ($stream as $delta) {
                    if ($delta->type === AiStreamEventType::Text) {
                        yield 'data: ' . json_encode(['text' => $delta->text]) . "\n\n";
                    }
                }

                $response = $stream->response();

                yield "event: done\ndata: " . json_encode([
                    'finish_reason' => $response->finishReason,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                ]) . "\n\n";
            } catch (AiStreamException $failure) {
                // The headers went out with the first byte, so the only way to
                // tell the browser is in-band. Emitting a done frame here would
                // be exactly the lie the refusal exists to prevent.
                yield "event: error\ndata: " . json_encode(['message' => $failure->getMessage()]) . "\n\n";
            }
        })(),
        200,
        ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'],
    );
});
```

Catch `AiStreamException` **inside** the generator. Once the first chunk has been written the
status line is already on the wire, so a failure cannot become a 500 — it has to be reported in
the stream the client is already reading.

## Decorating a stream

A stream is single-pass. To wrap one — for auditing, for budget enforcement — iterate the inner
stream, re-yield, and return its response as your generator's return value:

```php
public function streamChat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiStream
{
    $inner = $this->inner->streamChat($messages, $options);

    return new AiStream((function () use ($inner): Generator {
        foreach ($inner as $delta) {
            $this->observe($delta);

            yield $delta;
        }

        $response = $inner->response();
        $this->record($response);

        return $response;
    })(), $inner->providerName);
}
```

A failure inside the inner stream propagates out of the `foreach`, so the decorator neither
swallows it nor has to re-raise it.

## Testing without a provider

Providers take an optional `StreamTransportInterface`. Supply your own and the provider never
opens a socket:

```php
use Pulsar\AI\Streaming\StreamTransportInterface;

final class ScriptedTransport implements StreamTransportInterface
{
    /** @param list<string> $chunks */
    public function __construct(private readonly array $chunks) {}

    public function postStream(string $url, string $body, array $headers, int $idleTimeoutSeconds): iterable
    {
        yield from $this->chunks;
    }
}

$provider = new AnthropicProvider(apiKey: 'test', streamTransport: new ScriptedTransport($frames));
```

The chunks carry no framing meaning — split them anywhere, including mid-JSON or between the
`\r` and the `\n` of a CRLF. Reassembly is the reader's job, and splitting awkwardly is how you
prove it.

## Concurrency

There is none, deliberately. Streaming uses ordinary generators over a blocking read loop; no
fibers are involved. See [ADR-0071](adr/0071-a-fiber-keyed-map-is-not-concurrency.md) and the
[async model](async-model.md).
