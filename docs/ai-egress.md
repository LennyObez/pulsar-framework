# The AI egress boundary

`src/AI/Provider/` ships three clients. `AnthropicProvider` and `OpenAiProvider`
post whatever they are handed to an endpoint outside the deployment.

Nothing in `src/AI` inspected, classified or refused that payload — in a
framework that also ships a sensitive-data classifier, a pseudonymisation service
with an Article 17 forget path, `#[SensitiveParameter]` masking and a tokenisation
vault. A framework that can recognise personal data and then lets it leave
unchecked contradicts its own thesis more loudly than any missing feature could.

`Pulsar\AI\Egress\GuardedAiClient` is the missing half: a decorator over
`AiClientInterface` that every outbound payload runs into before a provider can
transport it.

## The shape

```php
use Pulsar\AI\Egress\{AiDestination, AiEgressPolicy, AuditingAiEgressObserver, GuardedAiClient};
use Pulsar\AI\Provider\AnthropicProvider;
use Pulsar\Security\Dlp\{DlpAction, DlpConfig, SensitivePatternRegistry};

$baseUrl = 'https://api.anthropic.com/v1';

$client = new GuardedAiClient(
    inner: new AnthropicProvider(apiKey: $key, baseUrl: $baseUrl),
    destination: AiDestination::fromBaseUrl('anthropic', $baseUrl),
    policy: new AiEgressPolicy(
        allowedHosts: ['api.anthropic.com'],
        onSensitiveData: DlpAction::Block,
    ),
    classifier: new SensitivePatternRegistry(new DlpConfig(enabled: true)),
    observer: new AuditingAiEgressObserver($auditLogger),
);
```

`GuardedAiClient` implements `AiClientInterface` in full, so it is a drop-in
replacement everywhere one is accepted — `RagPipeline`, `ToolCalling`,
`StructuredOutput`, and application code alike.

## What it checks, in order

1. **Coherence.** The wrapped client must answer to the provider name the
   destination was declared for.
2. **Destination.** The host must be on the operator's allow-list. This runs
   before any payload work, so a forbidden endpoint is refused without a request
   ever being constructed.
3. **Classification.** Every outbound string goes through
   `SensitivePatternRegistry`, or any other `SensitiveDataClassifierInterface` you
   supply. If the classifier did not examine the bytes, for any reason, the call
   is refused.
4. **Policy.** `AiEgressPolicy::$onSensitiveData` decides what a detection means.

Every method that sends bytes runs all four: `chat()`, `streamChat()`,
`complete()`, `embed()` and `structuredOutput()`. `embed()` matters more than it
looks — a RAG pipeline hands it the documents themselves, which makes it the
largest volume of personal data the module can emit.

## It fails closed

The interesting failure is the quiet one. A `SensitivePatternRegistry` built on a
`DlpConfig` with `enabled: false` answers every question with "nothing found"
without reading a byte, and **no wiring in the framework constructs that registry
at all** — so "there is no classifier" is the state of a stock deployment, not an
edge case.

So the guard never keys on `detected === false`. It keys on
`DlpScanStatus::isConclusive()` — _did the scanner examine these bytes_ — which a
disabled registry, an exhausted PCRE engine and a genuinely clean prompt used to
report identically. Each of the three now says which it is:

| Status      | Meaning                                      | Guard's response |
| ----------- | -------------------------------------------- | ---------------- |
| `Completed` | Every pattern ran to a verdict               | Apply the policy |
| `Disabled`  | DLP is off; nothing was examined             | Refuse           |
| `Failed`    | A pattern's match attempt failed inside PCRE | Refuse           |

A pattern validator that throws is refused too. A payload whose verdict raised is
an unclassified payload, and an unclassified payload is not one known to be safe.

## Destination control is a declaration, not an observation

This is the boundary's most important limit, so it is stated rather than implied.

`AiClientInterface::providerName()` returns a string literal compiled into the
provider class. `OllamaProvider` answers `'ollama'` however its `$baseUrl` is set,
and every provider keeps that `$baseUrl` private with no accessor. Nothing above
the transport can read the real endpoint.

So `AiDestination` takes the base URL from the composition root — the same value,
from the same place, that was handed to the provider's constructor — and derives
the host from it. The claim the guard can make is exact: _the endpoint the
operator was told about is on the allow-list_. It is **not** "the socket went
there". The coherence check in step 1 narrows the gap by comparing two
independent statements, which catches a destination declared for one provider
wrapped around another; it cannot catch a provider lying about its own name.

Host matching is exact and case-insensitive. There are no wildcards, deliberately:
the shapes an operator reaches for (`*.openai.com`, and from there `*.com`) turn a
list of endpoints into a pattern language, and a pattern language on an egress
allow-list fails in the permissive direction.

## Both defaults refuse

`allowedHosts` defaults to the empty list, which permits nothing. An empty list
meaning "unrestricted" would read the same in a config that forgot the key as in
one that deliberately opened everything — and the deployment that forgets is
precisely the one this guard is for.

`onSensitiveData` defaults to `DlpAction::Block`. Redaction sounds like the
careful choice and is the _less_ conservative one: it still sends a request, still
bills, still leaves a trace at the endpoint, and changes what the model was asked.
Blocking sends nothing.

The consequence arrives on day one: the shipped pattern set includes IPv4
addresses and a broad API-key shape, so a prompt discussing a server address is
refused by default. That is the intended direction of error. Tune it by editing
the registry's patterns — one classifier, adjusted in one place — not by loosening
the policy.

## A redaction is never silent

Under `DlpAction::Redact` the guard rewrites the prompt before sending it. A
rewrite nobody is told about is its own defect: the model answers a question the
application did not ask, and the application reads the answer as though it had.

`AiEgressObserverInterface` is therefore a **required** constructor parameter, not
a nullable one — the `null` branch is always the one people take. It is called on
every refusal, every redaction, and on an alert-only call that went out carrying
classified data. It is not called when the classifier examined the payload and
found nothing, which gives the useful invariant: **if the observer was not called,
nothing classified left the deployment on that call.**

Classified data found in a _structured_ field — a tool definition or a JSON schema
— is refused rather than masked, even under `Redact`. Masking a span inside a JSON
document produces a payload that is no longer the document, sent quietly, which is
the failure redaction exists to avoid.

## Streaming is covered

`streamChat()` runs the same guard before delegating, and the refusal lands on the
call rather than on first iteration. That distinction is load-bearing: the
providers build a streamed request eagerly and open the socket lazily, so a guard
that only fired when someone got round to iterating would let a refused call be
constructed, held, and connected.

The inner `AiStream` is returned untouched — the whole check is on the request, so
there is nothing to observe in the deltas, and the stream's single-pass and
terminal-event behaviour stays exactly as the provider built it. See
[Streaming AI responses](ai-streaming.md).

## Composition order

```
AuditingAiClient( GuardedAiClient( Provider ) )
```

Audit outermost, guard beneath it, provider innermost. A refused call must still
reach the audit sink, because "someone tried to send health data to a hosted
endpoint" is the event an assessor asks for first; with the guard outermost, the
refusal would raise before the audit decorator ever saw the call.

The consequence is stated rather than hidden: the audit decorator sees the payload
as the caller wrote it, not as it was redacted. That is the right way round — the
audit log lives inside the deployment and this guard is about what leaves it — but
it means the audit sink holds unredacted prompts and must be protected
accordingly.

`AuditingAiClient` never works the decision out for itself — it cannot see the
messages after the guard has rewritten them, and inferring "a refusal happened"
from an exception class would be a status the auditor invented. So the guard
reports through the one-slot `EgressDecisionSinkInterface` that `AiAuditWiring`
binds:

```php
new AuditingAiClient(
    inner: new GuardedAiClient(..., auditSink: $sink),
    auditLogger: $auditLogger,
    contentDigestKey: $key,
    egress: $source,   // the same PendingEgressDecision object
);
```

The sink is optional on `GuardedAiClient` because it exists only when the audit
decorator is composed; unlike the observer it is a reporting channel rather than a
control, and its absence removes no check.

**Clean calls are reported too.** The auditor distinguishes "a control looked and
passed it" from "no control reported anything", recording the second as
`not_observed`. A guard silent on the calls it passed would make a guarded
deployment indistinguishable, in the trail, from one with no egress control at
all.

Refusals carry a stable reason code — the `AiEgressDecision::REASON_*` constants —
so the trail is queryable without matching on prose.

## What this boundary does not catch

- **Model output.** The guard reads the request path only. A provider that echoes
  a prompt back is outside it.
- **Direct construction.** `src/AI` has no wiring and no `config/ai.php`;
  applications build providers themselves. Code that constructs
  `AnthropicProvider` directly and calls it bypasses the guard entirely. Wrapping
  is a composition-root discipline, not something the framework enforces.
- **What the classifier does not know.** Coverage is exactly the pattern set in
  `SensitivePatternRegistry` — credit cards (Luhn-checked), US SSNs, common API
  key shapes and IPv4 addresses. Names, addresses, dates of birth, non-US
  identifiers, free-text clinical notes and record numbers are **not** matched by
  anything shipped. A deployment relying on this boundary must register the
  patterns its own domain needs.
- **`ChatMessage::$toolCallId`.** A provider-generated correlation token echoed
  back verbatim; masking it would break the correlation it exists for, so it is
  not scanned. Tool _result content_ is scanned like any other message content.
- **Where the bytes actually went.** See the destination section above: the
  allow-list is checked against a declared endpoint, not an observed socket.
