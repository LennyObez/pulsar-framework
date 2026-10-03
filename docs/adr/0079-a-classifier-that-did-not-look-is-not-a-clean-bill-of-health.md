# ADR-0079: A classifier that did not look is not a clean bill of health

## Status

Accepted. Adds one `#[Api(since: '1.0.0-rc.12')]` decorator over
`Pulsar\AI\AiClientInterface` and six supporting `#[Api]` types under
`Pulsar\AI\Egress\`, one `#[Api]` exception, and one `#[Api]` enum plus one
trailing defaulted constructor parameter on the existing `#[Api]`
`Pulsar\Security\Dlp\DlpScanResult`. Everything is additive, which is what
[ADR-0001](0001-ci-gates-and-adr-discipline.md) asks for during the RC phase.
Continues [ADR-0041](0041-the-token-vault-takes-a-connection.md) — a capability
proved by a resolvable name is not proved — into the AI module, and builds on the
seam [ADR-0078](0078-a-stream-that-cannot-say-it-finished-has-not-finished.md)
landed.

## Context

`src/AI/Provider/` ships three clients. `AnthropicProvider` and `OpenAiProvider`
post whatever they are handed to an endpoint outside the deployment. Nothing in
`src/AI` inspected, classified or refused that payload.

The same framework ships `ControlSubject::PersonalData`,
`PersonalDataSealObserver` ([ADR-0066](0066-personal-data-is-measured-by-classifying-something.md)),
a pseudonymisation service with an Article 17 forget path, `#[SensitiveParameter]`
masking and a tokenisation vault. A framework sold to banks and hospitals that can
recognise personal data and then lets it leave to a third-party endpoint unchecked
contradicts its own thesis more loudly than any missing feature could.

### There is exactly one classifier of free text, and it is not the obvious one

Four classification vocabularies exist in the tree, and only one of them can read
a prompt:

- `Compliance\Control\ControlSubject` names the _estate a control regulates_. Not
  a content classifier.
- `Workflow\Storage\ClassificationLevel`, `Security\Compliance\DataClassification`
  and `Api\Resource\Attribute\ClassificationTag` classify a _declared field_ on a
  structured record. A `ChatMessage::$content` is free text with no field
  identity; there is nothing for them to classify.
- `Security\Dlp\SensitivePatternRegistry` reads bytes and returns matches. It is
  the only one that applies.

So the seam uses that registry and adds no second notion of personal data. Two
classifiers that can disagree would be worse than one.

### The classifier answers "nothing found" in three unrelated situations

`SensitivePatternRegistry::scan()` returned `DlpScanResult` whose only report on
an empty result was `detected === false`. Three distinct causes produced it:

1. Every pattern ran and matched nothing.
2. `DlpConfig::$enabled` is false, so the first line returned
   `DlpScanResult::clean($content)` **without consulting a single pattern**. No
   wiring in the tree constructs this registry at all, so this is the state of a
   stock deployment rather than an edge case.
3. `preg_match_all()` returned `false` — the PCRE engine gave up — and the method
   compared that return against `> 0`. `false > 0` is `false`, which lands on the
   same side of the comparison as "matched nothing". Observed:
   `/^(?:[a-z]+)+$/` against forty `a`s and a `!` returns `false` with
   `preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR`, and applications register
   their own patterns through the public `register()`.

A consumer protecting a log line can reasonably ignore the difference. A consumer
deciding whether personal data may leave the deployment cannot: for it, cases 2
and 3 have to mean refusal.

A claim checked and **not** confirmed, recorded because the fix was nearly built
on it: the shipped credit-card pattern `/\b(?:\d[ -]*?){13,19}\b/` looks like a
backtracking hazard and was expected to exhaust the limit on a long prompt. It
does not — driven at 500, 5 000 and 20 000 characters of digits it returns in
under a millisecond with `PREG_NO_ERROR`, because the interior `\b` cannot match
inside a digit run. Case 3 is reachable through application-registered patterns,
which is enough, and the shipped set is not itself the route.

### The destination cannot be observed, only declared

`AiClientInterface::providerName()` returns a string literal compiled into the
provider class; `OllamaProvider` answers `'ollama'` however its `$baseUrl` is set,
and all three providers keep `$baseUrl` private with no accessor. Nothing above
the transport can read the endpoint the socket will reach. ADR-0041 records that
defect in its general form.

## Decision

**1. `DlpScanStatus` is produced by the component that measures it.** `Completed`,
`Disabled`, `Failed`, with `isConclusive()` as the single predicate. It rides on
`DlpScanResult` as a trailing defaulted parameter, and `scan()` sets it. Consumers
decide what each status means rather than having a policy forced on them: the
existing `DlpScanMiddleware` and `LogDlpFilter` are unchanged, and `LogDlpFilter`
in particular must not start throwing on the logging path.

The alternative was to read `DlpConfig::$enabled` a second time inside the guard.
That is two sources for one fact, which is the shape this repository keeps finding
defects in.

`HtmlAwareDlpScanner::mergeResults()` takes the less conclusive of its two halves.
Reporting the pair as `Completed` because neither detected anything would have
turned two "did not look" answers into one "looked and found nothing" — the exact
laundering the enum exists to stop.

**2. `GuardedAiClient` is the seam, and it fails closed on the status.** It keys on
`isConclusive()`, never on `detected === false`. A validator that throws is
refused too. It guards all five sending methods — `chat`, `streamChat`, `complete`,
`embed`, `structuredOutput` — including `AiRequestOptions::$systemPrompt`, which a
guard that only walked the messages would ship verbatim.

**3. Streaming is guarded on the call, not on iteration.** The providers build a
streamed request eagerly and open the socket lazily. A guard that fired only when
someone iterated would let a refused call be constructed and held. The inner
`AiStream` is returned untouched: the whole check is on the request, so wrapping
the generator would add a decorator that observes nothing.

**4. Both policy defaults refuse.** `AiEgressPolicy::$allowedHosts` defaults to
empty, permitting nothing — an empty list meaning "unrestricted" reads the same in
a config that forgot the key as in one that opened everything.
`$onSensitiveData` defaults to `DlpAction::Block` rather than `Redact`: redaction
still sends a request, still bills, still leaves a trace at the endpoint, and
changes what the model was asked. Host matching is exact, with no wildcards — a
pattern language on an egress allow-list fails in the permissive direction.

**5. The destination is declared by the composition root and cross-checked.**
`AiDestination::fromBaseUrl()` derives the host from the same base URL the
provider was constructed with, so the host is a function of the URL rather than a
second thing someone typed. Every call then compares the declared provider name
against the wrapped client's own `providerName()` and refuses on disagreement.
That catches a destination declared for one provider wrapped around another; it
cannot catch a provider lying about its own name, and the documentation says so
rather than implying a guarantee the code cannot make.

**6. A redaction is never silent.** `AiEgressObserverInterface` is a required
constructor parameter. A rewrite nobody is told about means the model answers a
question the application did not ask and the application reads the answer as
though it had; making the observer optional would ship that as the default,
because the `null` branch is always the one people take. Classified data in a
structured field — a tool definition, a JSON schema — is refused rather than
masked, since masking a span inside a JSON document produces a payload that is no
longer the document.

**7. Audit wraps the guard, not the other way round, and the guard reports.**
`AuditingAiClient(GuardedAiClient(Provider))`. A refused call must still reach the
audit sink; "someone tried to send health data to a hosted endpoint" is the event
an assessor asks for first.

The decision travels through the one-slot `EgressDecisionSinkInterface` that
`AiAuditWiring` binds, because the auditor must not work it out for itself: it
never sees the messages after the guard has rewritten them, and inferring a
refusal from an exception class would be a status the auditor invented rather than
one the control measured — the defect [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md)
names. The guard reports on **every** call, clean ones included, because the
auditor records an unreported call as `not_observed`: a guard silent on its passes
would make a guarded deployment indistinguishable, in the trail, from one with no
egress control at all.

One gap in the mapping, recorded rather than papered over. Under
`DlpAction::Alert` the content is inspected and forwarded **carrying** classified
data. The audit vocabulary has `passed()`, `redacted()` and `refusal()` and no
fourth factory, so that call is reported as `passed()` — literally true, and it
loses the categories. They survive in full through
`AiEgressObserverInterface`, which is told about every alert-only call. A
`forwardedCarrying()` factory on `EgressDecision` would close it.

## Consequences

Thirteen checks were observed failing against the unfixed code before they passed,
each against a recording transport that captures the serialised request body — so
the claims are about bytes rather than about PHP objects. The sharpest is removing
the guard from `streamChat()` alone: all nine unstreamed checks still pass while
four streamed ones fail, which is what a seam covering only the non-streaming
calls would have looked like.

The Block default will refuse prompts that discuss IPv4 addresses, because the
shipped pattern set matches them. That is the intended direction of error and is
tuned by editing the registry's patterns rather than by loosening the policy.

The boundary is opt-in. `src/AI` has no wiring and no `config/ai.php`;
applications construct providers themselves, and code that constructs
`AnthropicProvider` directly still bypasses the guard. Closing that requires an
`AiWiring` and a shipped config file, which is a separate change with its own ADR.
