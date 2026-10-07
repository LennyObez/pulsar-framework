# ADR-0080: An inference nobody recorded is one nobody can defend

## Status

Accepted. Adds `Pulsar\AI\Audit\` — one `#[Api]` decorator over
`Pulsar\AI\AiClientInterface`, one `#[Api]` enum, one `#[Api]` value object, two `#[Api]`
interfaces and one `#[Internal]` channel — plus `Pulsar\Core\Wiring\AiAuditWiring` and one new
`SubKeyId` case. Everything is additive, which is what
[ADR-0001](0001-ci-gates-and-adr-discipline.md) asks for during the RC phase.

Applies [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md):
twenty-four mutations were watched failing against the unmutated code before any of the claims
below were believed. Applies
[ADR-0045](0045-a-control-status-is-observed-not-written.md) and
[ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md): a token count, a
finish reason and an egress verdict are facts produced by the component that measured them, and
this decorator copies them rather than deriving them. Composes with
[ADR-0079](0079-a-classifier-that-did-not-look-is-not-a-clean-bill-of-health.md), which fixed
the ordering `AuditingAiClient(GuardedAiClient(Provider))`, and builds on the streaming seam
[ADR-0078](0078-a-stream-that-cannot-say-it-finished-has-not-finished.md) landed. Constrained
by [ADR-0071](0071-a-fiber-keyed-map-is-not-concurrency.md): no fibers.

## Context

`AiAuditLoggerInterface` exists, and it exists in the wrong place. It ships in
`extensions/ai-governance`, which is off by default and absent from the default
`enabled_products` list. So the honest description of a stock deployment was: it can call a
language model, act on the answer, and leave no record anywhere — while the framework's own
argument is that its default posture is the one you can defend.

The obligations are not optional for the deployments this framework targets. SOC 2 CC7 wants
the event recorded. ISO/IEC 42001 clause 9.1 wants monitoring and measurement of the AI system.
The EU AI Act's logging duty for high-risk systems wants a trail that survives the year. None
of them is answered by "install the optional extension".

Two further gaps mattered as much as the placement:

- **The extension's `logInvocation()` takes `$sanitizedInput` and `$sanitizedOutput` arrays
  from its caller.** Whether anything was sanitised is the caller's claim, unverified, and the
  arrays land verbatim in the audit file. A trail that stores the personal data the egress seam
  had just refused to send is a second leak into a file with a longer retention than anything
  else in the estate.
- **Nothing recorded a REFUSAL.** ADR-0079's guard raises before the transport. A decorator
  that records after a successful return sees none of them, and "someone tried to send health
  data to a hosted endpoint" is the event an assessor asks about first.

## Decision

**1. It fails closed at boot, and this departs from the house pattern on purpose.** Every other
wiring in `src/` that touches the trail asks `has(AuditLoggerInterface::class)` and falls back
to `NullAuditLogger`. That is right where the audit entry is a SIDE EFFECT of work that still
has value unrecorded — a brute-force detector still detects, a mail still sends. For an
inference the record IS the regulatory artefact, so `AiAuditWiring` refuses to boot a
deployment that binds `AiClientInterface` with no audit chain behind it. The precedent is
`ComplianceLoggingWiring`, which makes the same call for the same reason: an operator who
believes log output is masked must never silently emit it unmasked, and an operator who
believes model calls are recorded must never silently make them unrecorded. For a bank an
unauditable model call is worse than no model call.

There is no configuration key that turns this off. `ai.audit.enabled` would be the optional
extension again in a shorter costume, and the first deployment to flip it would be the one
under time pressure. A deployment that does not bind an AI client is not asked for a key it has
no use for.

**2. The content is digested, never stored, and the digest is KEYED.** Prompt, completion,
system prompt and JSON schema each become a BLAKE2b digest over a length-prefixed rendering,
alongside a character count. Keyed rather than a bare hash, because a prompt is low-entropy far
more often than not — a customer name, an account number, one of a few hundred templates — and
an unkeyed hash of one is recovered by guessing. The key is `SubKeyId::AiInferenceDigest` under
context `ai_cdgst`, a pair of its own: the digests are PUBLISHED next to the entry HMAC and are
computed over attacker-influenced text, so deriving them under the audit chain's own
`(2, audit___)` would put a chosen-message MAC oracle under the key that makes the chain
tamper-evident.

What the digest buys is exact: possession of the audit file alone reveals nothing; an
investigator holding the file AND a candidate prompt can prove the match; anyone holding the
file can prove two entries carried the same prompt without learning what it was. The
length-prefixing is not decoration — without it `["a\nb"]` and `["a", "b"]` render to the same
bytes, and a colliding digest is a false match in an investigation.

**3. Exception MESSAGES are never recorded, only classes.** Providers echo the offending
request back in their errors; `getMessage()` in the metadata would put the prompt into the file
that exists partly to prove the prompt was never stored. Tool call NAMES are recorded and tool
call ARGUMENTS are not, for the same reason — the arguments are the model's copy of whatever
the prompt contained, and a model reaching for `transfer_funds` is the fact worth having.

**4. The egress verdict is copied, and its absence is reported as absence.** `ai_egress` has
three states that never collapse: `not_observed` when no channel is composed, `no_finding` when
a channel is composed and reported nothing, and the control's own outcome
(`passed`/`redacted`/`refused`) otherwise. `AiAuditWiring` consumes an
`EgressDecisionSourceInterface` binding and never creates one, because a channel bound next to
no control would turn "nobody looked" into "looked and found nothing" — the laundering ADR-0079
exists to stop, re-created by the thing built to record it.

The channel itself is one slot and reading it CLEARS it, so a decision belongs to exactly one
inference. For a streamed call the decision is taken when the call is made rather than when the
record is written, because those are different moments: a caller holding two streams open at
once would otherwise have the second call's decision filed against the first.

**5. The actor is the seam, not an invented user.** Entries carry
`AuditActor::system('ai.inference')`. This decorator measures the call, not the identity behind
it; the human is reached by joining on the correlation id the audit logger stamps from the live
request context, to the authentication entry that did measure identity. Naming a user here
would be a fact produced by a component that did not observe it.

**6. Streaming is recorded at the end, and every ending is an ending.** Token counts are not
final until the terminal event, so a record written when `streamChat()` returned would say
nothing about cost. Three endings, one entry each: read to completion, recorded complete;
broken or refused mid-flight, recorded incomplete with whatever partial accounting arrived —
absent numbers stay absent rather than becoming zero; abandoned by a consumer that stopped
iterating and dropped the stream, recorded incomplete via the generator's `finally`, which PHP
runs when a suspended generator is destroyed. The third is the one an audit trail loses
quietly, because it produces neither a response nor an exception.

## Consequences

Twenty-four mutations were observed failing. The sharpest three: removing the `finally` leaves
every other streaming check green while the abandoned-stream check fails, which is what a
decorator covering only the endings that announce themselves would have looked like; replacing
`$failure::class` with `$failure->getMessage()` fails the leak checks and nothing else;
fabricating the egress channel in the wiring fails exactly the check that says absence must
read as absence.

`AiAuditWiring` is a no-op in a stock tree, and that is a statement about the tree rather than
about the wiring: `src/AI` has no wiring and no `config/ai.php`, so nothing binds
`AiClientInterface` at boot. ADR-0079 records the same limit from the other side. What this ADR
fixes is that the moment anything DOES bind it — an `AiWiring`, a project, an extension that
registers through the composition root — the client that comes out is audited or the boot
fails. Closing the remaining path, where an extension constructs a provider directly and never
puts it in the container, needs `AiWiring` and a shipped config file; that is a separate change
with its own ADR.

The `ai-governance` extension's `AiAuditLogger` is left alone. It records governance events —
deployment gates, human overrides, impact assessments — which are not inferences and are not
what this decorator sees. Its `logInvocation()` is now the redundant half, and pointing
projects at the core decorator instead is a documentation change rather than a deprecation.
