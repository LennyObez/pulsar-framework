# ADR-0048: A guard is something a request runs into

## Status

Accepted, with decision 1 superseded by
[ADR-0052](0052-an-authorization-decision-does-not-run-the-application.md).

Wires two security controls that no code path reached, deletes a third that
guarded against something the kernel already makes impossible, and fixes two defects
in the newly-reachable path that only became observable once anything executed it.

The finding in decision 1 stands: the Gate recorded nothing, and every grant and
refusal the framework made was invisible to the audit chain. The **mechanism** chosen
here — dispatching the decision through the application's `EventDispatcherInterface`
— does not. It put every listener the application had registered inside every
authorization decision, where one that throws turns a grant into a `500`, one that is
slow makes every check slow, and one that asks the Gate a question re-enters the
decision it is being told about. It also cost fourteen times what the decision cost.
ADR-0052 keeps the record and replaces the mechanism with a dedicated sink; read
decision 1 below as history, not as the shipped design.
Removes one `#[Api]` exception factory (`ApiException::entitySerializationBanned`), one
`#[Api]` constructor parameter (`ApiConfig::$entitySerializationBanEnabled`), one
`#[Internal]` class, and one shipped config key. Continues
[ADR-0041](0041-the-token-vault-takes-a-connection.md)'s finding into the auth subsystem.

## Context

Three controls in `src/Auth/` and `src/Api/` were built, commented, unit-tested, and
reachable from nothing. Each was found by grep and by reading the composition root; none
is a guess.

### The Gate reached its decisions in silence

`Gate::allows()` announces every grant and every denial by dispatching
`AuthorizationGranted` / `AuthorizationDenied` — and both `dispatchGranted()` and
`dispatchDenied()` open with `if ($this->eventDispatcher === null) { return; }`.
`AuthWiring` constructed the Gate with two arguments. There was no third.

So the framework whose compliance story is a tamper-evident HMAC-chained audit log
recorded no authorization decision anywhere. `AuthorizationMiddleware` had its own
optional `AuditLogger` and covered denials that arrived through a route's `permissions`
attribute; every decision reached by a policy, a service, or a controller calling
`allows()` directly left nothing behind. For an assessor, "show me the access decision"
is the first question, and the answer was a log line that was never written.

A dispatcher alone would not have fixed it. A dispatch nobody listens to is still not an
audit trail, and there was no listener either.

### `AccountTakeoverGuard` was constructed nowhere

The guard compares the address and device a credential change arrives from against the
ones the session was opened on, and enforces a re-authentication window over five named
operations: password change, e-mail change, MFA disable, recovery-code regeneration,
account deletion. Grep for `new AccountTakeoverGuard` outside its own test returns
nothing. No middleware, no wiring, no controller.

The practical consequence is the plain reading: in every Pulsar deployment, a password
change succeeded on the strength of a session cookie, from any address, on any device,
however long ago the person had last proved who they were. That is the textbook takeover,
and the class that describes it in detail sat next to the path it was describing.

### `EntitySerializationGuard::check()` had no caller

`ApiWiring` built the guard and bound it in the container. Nothing ever called it. Its
docblock states a "Finding E invariant": entities must never bypass the resource
transformation layer.

Unlike the two above, wiring this one would have been wrong.

## Decision

**1. The Gate gets a dispatcher, and a listener that turns its decisions into records.**

> Superseded by [ADR-0052](0052-an-authorization-decision-does-not-run-the-application.md).
> The Gate hands its decisions to a dedicated `AuthorizationDecisionSinkInterface`; no
> event dispatcher, and no application listener, is on the authorization path. The rest
> of this section describes what shipped between the two ADRs.

`AuthWiring` resolves `EventDispatcherInterface` — `EventWiring` runs earlier in
`WiringList`, so it is there to be had — and passes it to the Gate. In the same block it
registers `AuthorizationDecisionAuditListener`, which writes each decision to the
HMAC-chained log as `AuditEvent::Authorization`. Wiring one without the other would have
restored the same silence in a less obvious place, so they are wired in one breath or not
at all.

Three properties make the resulting entry evidence rather than a log line, and each comes
from a different part of the record. Tamper evidence comes from the chain: entries are
HMAC-linked to their predecessors, so an altered or excised decision breaks verification.
Forgery resistance for the payload comes from the envelope's `payloadHash`, recorded
alongside the decision. Replay detection comes from the CSPRNG `decision_nonce` the event
mints at decision time — two entries carrying one nonce are one decision written twice.

A sink failure must never unwind the decision that caused it. `EventDispatcher` re-throws
the first listener error after its loop, so an exception escaping the listener would
surface from `Gate::allows()` and turn a transient audit fault into a failed request. It
is caught and reported at `critical` instead.

`AuthorizationDecisionAuditWiringTest` asserts the effect — an entry in the sink, chained
and verifying against the key — rather than that a dispatcher was passed. The distinction
matters: an assertion about the constructor argument would still have passed with no
listener registered, which is half the defect.

**2. `AccountTakeoverGuard` is wired behind a `sensitive` middleware alias.**
`SensitiveOperationMiddleware` runs the guard over a route that names the operation it
performs, the way a route already names the permissions it requires:

```php
new Route(
    methods: [Method::POST],
    path: '/account/password',
    handler: [PasswordController::class, 'change'],
    attributes: ['sensitive_operation' => 'password_change'],
    middleware: ['auth', 'sensitive'],
);
```

A route attribute, rather than a global pipe, because the framework cannot know which of
an application's routes change credentials, and a control that guesses would either miss
the ones that matter or refuse the ones that do not.

Two checks run. The re-authentication window is satisfied by either a fresh login or a
completed step-up challenge, so an application already running the `step-up` alias does
not prompt twice for one request — which required `SessionGuard` to stamp when
credentials were last presented, distinct from the session's `createdAt` (`regenerate()`
carries metadata across, so `createdAt` answers "how old is this browser's session", not
"how recently did this person prove who they are"). The takeover comparison refuses a
change arriving from a different address _and_ a different device; one of the two passes
but is recorded, because a mobile network re-issuing an address is ordinary and a swapped
device is not.

The alias is registered only when the session-backed guard, the session manager, and a
logger all exist, because the guard cannot evaluate anything without all three. An alias
resolving to a middleware missing its dependencies would be the same unreachable control
wearing a route.

**3. Two defects the wiring exposed, which no test could have found while nothing ran.**

`AccountTakeoverGuard::evaluate()` records its verdict against `SessionMetadata::$userId`.
Nothing in `src/` populates that field — `SessionManager::setUserId()` has no caller
outside tests — so on every real session it is null, and `AuditLogger` rejects a null
actor by design rather than writing an unattributable record. The one entry the control
exists to produce was therefore the one thing a detected takeover destroyed. The
middleware now stamps the identity it just authenticated onto the copy of the metadata it
hands the guard; the session's own metadata is untouched. The guard's existing unit test
did not catch this because its fixture hard-codes `userId: 'user-1'` — a value production
never produces.

`SensitiveOperationMiddleware` also let the audit sink decide the request. Its refusal
path caught `RandomException | JsonException | SodiumException`, which is what
`AuditLogger::log()` declares — but a sink signals a failed write with `SecurityException`,
which is not among them, and the `evaluate()` call was not guarded at all. A full disk
therefore turned a refusal into a 500, and turned an _allowed_ elevated-risk request —
an ordinary address change — into a 500 as well. Both sites now catch `Throwable`, report
at `critical`, and refuse. A control an attacker can switch off by filling a disk is not a
control.

**4. `EntitySerializationGuard` is deleted, and the path is not re-guarded.**

There is no auto-serialization path for an entity to travel down. `Kernel::invokeHandler()`
accepts a `ResponseInterface` or a string and throws `RoutingException::unexpectedReturnType()`
for everything else — unconditionally, in every environment, with no configuration
governing it. A controller returning a domain entity does not produce a leaked entity; it
produces a refused request, before any encoder is reached.

The guard therefore did not protect a reachable path. It duplicated a structural
invariant, downstream of the point where the invariant is enforced, behind an
`api.entity_serialization_ban` config key that could switch the duplicate off — and its
`debugMode` branch meant the two halves of its own behaviour disagreed: throw in
development, return `true` in production and let the caller decide, where the caller was
nobody.

Wiring it would have added a second check behind a check that cannot be got past, and put
a configuration switch in front of a guarantee that currently has none. Deleting it
removes a row from the compliance surface that read as though the framework inspected
controller return values, which it does not and does not need to.

What the deletion rests on is now a test rather than an argument.
`EntityNeverAutoSerializesTest` dispatches a controller returning a domain entity through
a real kernel and asserts that neither the response body nor the kernel's own diagnostic
carries any of the entity's fields, and does the same for an array. If a future change
introduces an auto-serializing return path, it fails — before anyone relies on the
reasoning above.

Removed with it: `ApiException::entitySerializationBanned()` (`#[Api]`),
`ApiConfig::$entitySerializationBanEnabled` (`#[Api]` constructor parameter), and the
`entity_serialization_ban` key from `config/api.php`. All three are breaking changes to
1.0.0-marked surface during RC, which [ADR-0001](0001-ci-gates-and-adr-discipline.md)
asks us to justify rather than avoid. The justification is the same in each case: they
exist only to configure a class that was never asked a question, and carrying them to GA
would freeze a switch that governs nothing until the next major version.

## Alternatives considered

**Pipe `SensitiveOperationMiddleware` globally and detect sensitive routes by path.**
Rejected. The framework does not know an application's route names, and a heuristic over
paths would both miss `/settings/security/email` and refuse `/account/password-strength`.
The route attribute makes the declaration explicit, greppable, and reviewable — the same
argument the `permissions` attribute already won.

**Fail open when the audit sink is unavailable, so a broken recorder cannot stop
password changes.** Rejected for a framework aimed at banking, healthcare, and legal
deployments. The failure mode of refusing is that a credential change is unavailable
while the sink is down and the operator sees `critical` lines saying exactly why. The
failure mode of passing is that the highest-value operation in the system runs
unevaluated and unrecorded, at a moment an attacker may have arranged. The first is an
outage; the second is the defect this ADR is about.

**Add `setUserId()` to `SessionInterface` so session metadata carries the identity
generally.** Rejected for now. Adding a method to an `#[Api(since: '1.0.0')]` interface
breaks every third-party implementation, and the only security-critical reader of
`SessionMetadata::$userId` is the guard fixed above — `SessionManager`'s PCI-DSS 8.2.8
idle gate keys off `SessionConfig::$authenticatedMarkerKeys` in the session _data_, not
off this field, precisely so the two cannot desync. Stamping at the one call site that
needs it fixes the reachable defect without a breaking interface change. That
`setUserId()` remains a public method the framework itself never calls is recorded here
rather than fixed, because it is a question about the session API's shape and deserves
its own assessment.

**Keep `EntitySerializationGuard` and call it from the kernel.** Rejected; see decision 4.

## Consequences

Applications gain audit entries they did not have. A deployment with a busy authorization
path will see `AuditEvent::Authorization` volume it must size its sink for — grants as
well as denials, because "who was allowed to do this" is the half an assessor asks about
after an incident. Sinks that cannot take the volume should filter at the sink, not by
removing the recorder, so that the filtering decision is visible in configuration
instead of invisible in wiring. (Under
[ADR-0052](0052-an-authorization-decision-does-not-run-the-application.md) the entries
are written after the response rather than inside the decision, so the volume is the
same and the latency is not.)

No route carries the `sensitive` alias until an application adds it. That is deliberate —
the framework cannot pick an application's credential-changing routes — and it means this
ADR does not, by itself, make any existing deployment safe. It makes the control
reachable, documents where to put it in `docs/authentication.md`, and pins with tests
that a route carrying it blocks a takeover and records the attempt. The remaining step is
the application's, and it is one route attribute.

Behind a load balancer the guard needs a `TrustedProxy` bound, or every request carries
the balancer's `REMOTE_ADDR` and the address comparison runs one constant against
another — a control that evaluates and always finds nothing. `AuthWiring` passes the
`TrustedProxy` when one is bound, and the documentation says why it matters to
correctness rather than only to accuracy.

An application reading `ApiConfig::$entitySerializationBanEnabled` or catching the
`ApiException` from `entitySerializationBanned()` will not compile. Neither could have
had a live consumer: the property was read at exactly one site, which is deleted, and the
exception was thrown at exactly one site, which is deleted.
