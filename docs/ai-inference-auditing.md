# Inference auditing

Every call the core AI layer makes to a language model writes one entry into the
tamper-evident, HMAC-chained audit trail — including the calls that fail and the calls an
egress control refuses. Nothing you send to the model, and nothing it sends back, is stored in
the clear.

The decision behind it is recorded in
[ADR-0080](adr/0080-an-inference-nobody-recorded-is-one-nobody-can-defend.md).

## What it is

`Pulsar\AI\Audit\AuditingAiClient` decorates `Pulsar\AI\AiClientInterface`. It implements the
same five sending methods, delegates each one, and records what happened:

```php
$client = new AuditingAiClient(
    $provider,          // any AiClientInterface
    $auditLogger,       // Pulsar\Audit\AuditLoggerInterface
    $contentDigestKey,  // a KDF sub-key, at least 16 bytes
    $egressChannel,     // optional, see "Composing with the egress guard"
);
```

`Pulsar\Core\Wiring\AiAuditWiring` composes it for you at boot, and refuses to boot without it
— see [Fail closed](#fail-closed).

## What lands in the trail

One entry per call, with `action = 'ai.inference'` so a single filter finds every model call a
deployment ever made. `event` is `Communication`; `actor` is `system:ai.inference`; `resource`
is `<provider>:<model>` when either side named a model.

| Field                                                                                                                          | Meaning                                                         |
| ------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------- |
| `ai_operation`                                                                                                                 | `chat`, `stream_chat`, `complete`, `structured_output`, `embed` |
| `ai_provider`                                                                                                                  | The wrapped client's own `providerName()`                       |
| `ai_model_requested`                                                                                                           | The model the caller asked for                                  |
| `ai_model_reported`                                                                                                            | The model the provider said it used                             |
| `ai_input_tokens`, `ai_output_tokens`, `ai_total_tokens`                                                                       | The provider's own accounting                                   |
| `ai_finish_reason`                                                                                                             | Why generation stopped                                          |
| `ai_latency_us`                                                                                                                | Measured across the call, monotonic                             |
| `ai_message_count`, `ai_roles`, `ai_input_count`                                                                               | The shape of the conversation                                   |
| `ai_prompt_chars`, `ai_completion_chars`, `ai_system_prompt_chars`                                                             | Sizes, not contents                                             |
| `ai_prompt_digest`, `ai_completion_digest`, `ai_system_prompt_digest`, `ai_schema_digest`                                      | Keyed digests, see below                                        |
| `ai_tool_call_count`, `ai_tool_names`                                                                                          | Which tools the model reached for                               |
| `ai_temperature`, `ai_max_tokens`, `ai_response_format`, `ai_timeout_seconds`, `ai_tools_offered`                              | The request as configured                                       |
| `ai_egress`, `ai_redaction_applied`, `ai_redaction_spans`, `ai_redaction_categories`, `ai_egress_refused`, `ai_refusal_reason` | What the egress control decided                                 |
| `ai_stream_complete`, `ai_stream_abandoned`, `ai_stream_deltas`                                                                | How a streamed call ended                                       |
| `ai_error`                                                                                                                     | The exception CLASS, on a failed call                           |

The `outcome` is `Denied` when an egress control refused the call, `Error` when anything else
raised, and `Success` otherwise. A refusal outranks the exception that carried it, because the
control MEASURED a denial while the exception class is only how it travelled.

## What never lands in the trail

The prompt. The completion. The system prompt. Tool call arguments. The JSON schema. The
embedding inputs. Provider exception messages.

An audit file has a longer retention than anything else in an estate. Writing into it the
personal data an egress seam had just refused to send would move the leak rather than prevent
it. Provider errors quote the offending request back, which is why only the exception's class
name is kept.

### What stands in for the content

A keyed BLAKE2b digest over a canonical, length-prefixed rendering, plus a character count.

Keyed, not a plain hash. A prompt is usually low-entropy — a customer name, an account number,
one of a few hundred templates — so an unkeyed hash of one is recovered by guessing, and the
audit file would hold a reversible copy of the very data it is supposed to prove was not
stored. The key is derived from the application master key under
`SubKeyId::AiInferenceDigest` / context `ai_cdgst`, a pair used by nothing else.

What you can do with a digest:

- Prove a specific prompt was, or was not, the one sent — if you hold both the file and the
  candidate text.
- Prove two entries carried the same prompt, without learning what it was.
- Show an assessor that the reviewed system prompt was the one in force, by digesting it.

What you cannot do: recover the prompt from the file.

## Fail closed

`AiAuditWiring` runs near the end of the boot sequence. If nothing is bound to
`Pulsar\AI\AiClientInterface`, it does nothing — a deployment that does not use the AI layer is
not asked for a key it has no use for.

If an AI client IS bound and the audit chain is not — which is what a deployment with no
`PULSAR_MASTER_KEY` looks like, because `SecurityWiring` skips its whole crypto block — **boot
fails** with a `ConfigException`. There is no configuration key that softens this.

That is a deliberate departure from the pattern every other wiring follows. Elsewhere a missing
audit logger degrades to `NullAuditLogger`, correctly, because there the entry is a side effect
of work that still has value unrecorded. For an inference the record is the regulatory
artefact. Degrading would produce exactly the posture this component exists to end — a
regulated deployment calling a language model and keeping no evidence — with a framework
component named "audit" in the graph to make it look handled.

To boot: set `PULSAR_MASTER_KEY`, or do not bind an AI client.

## Composing with the egress guard

[ADR-0079](adr/0079-a-classifier-that-did-not-look-is-not-a-clean-bill-of-health.md) ships
`Pulsar\AI\Egress\GuardedAiClient`, which classifies every outbound payload and refuses,
redacts or forwards it. The order is fixed:

```php
new AuditingAiClient(new GuardedAiClient($provider, ...), ...)
```

Audit **outside** the guard. Inverted, the auditor would run before the guard had decided
anything, every record would say the egress decision was not observed, and a refused call —
which raises past the auditor — would be filed as a plain provider error.

The two talk through a one-slot channel. `Pulsar\AI\Audit\Internal\PendingEgressDecision`
implements both halves: `EgressDecisionSinkInterface`, which the guard is constructed with, and
`EgressDecisionSourceInterface`, which the auditor reads. Reading clears the slot, so a
decision is attributed to exactly one inference and never inherited by the next call.

```php
$channel = new PendingEgressDecision();

$guarded = new GuardedAiClient($provider, $destination, $policy, $classifier, $observer, $channel);
$client  = new AuditingAiClient($guarded, $auditLogger, $digestKey, $channel);

$container->instance(EgressDecisionSinkInterface::class, $channel);
$container->instance(EgressDecisionSourceInterface::class, $channel);
```

Bind the channel **only** where a guard actually reports into it. `AiAuditWiring` consumes an
existing binding and never creates one, for that reason: with a channel present and nothing
reported, the auditor records `no_finding` — "the control examined this payload and found
nothing" — and a channel bound next to no control would make every record say that in a
deployment where nothing looked at all.

The three egress states, kept apart on purpose:

| `ai_egress`    | Means                                                                      |
| -------------- | -------------------------------------------------------------------------- |
| `not_observed` | No egress channel is composed. Nothing looked.                             |
| `no_finding`   | A channel is composed and reported nothing for this call.                  |
| `passed`       | The control examined the payload and forwarded it.                         |
| `redacted`     | The control rewrote part of it. `ai_redaction_spans` says how much.        |
| `refused`      | The control stopped the call. `ai_refusal_reason` carries its reason code. |

## Streaming

Token counts are not final until a stream reaches its terminal event, so the record is written
when the stream ENDS, not when `streamChat()` returns. Every ending writes exactly one entry:

- **Read to completion** — `ai_stream_complete: true`, with the provider's final counts.
- **Broken or refused mid-flight** — `ai_stream_complete: false`, `ai_error` naming the
  exception class, and whatever partial accounting had arrived. A number the provider never
  reported is absent from the entry rather than recorded as zero.
- **Abandoned** — a consumer that stopped iterating and dropped the stream produces neither a
  response nor an exception, and is the ending an audit trail loses quietly. It is recorded
  with `ai_stream_abandoned: true` when the suspended generator is destroyed.

For a streamed call the egress decision is taken when the call is MADE, because that is when
the guard inspects the request. A caller holding two streams open at once would otherwise have
the second call's decision filed against the first.

## Relationship to the `ai-governance` extension

`extensions/ai-governance` records governance events — deployment gates, human overrides,
impact assessments, bias findings. Those are not inferences, and this decorator does not
produce them; keep the extension if you need them.

Its `AiAuditLoggerInterface::logInvocation()` is the redundant half. It takes
`$sanitizedInput` and `$sanitizedOutput` arrays on the caller's word and writes them verbatim,
which is the leak this decorator's digests exist to avoid. Prefer `AuditingAiClient` for
inference records.
