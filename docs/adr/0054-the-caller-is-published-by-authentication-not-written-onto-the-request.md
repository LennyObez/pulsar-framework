# ADR-0054: The caller is published by authentication, not written onto the request

## Status

Accepted. Adds one `#[Internal]` class (`Pulsar\Auth\AuthenticationState`), two methods
to `Pulsar\Auth\SecurityContext` (`established()`, `tryIdentity()`), one constructor
parameter to `AuthenticationMiddleware`, and one `OptionalBinding` to
`ModelBindingWiring`. `AuthenticationMiddleware` stops reading the `identity` request
attribute; `ModelBindingWiring` stops reading `_identity`, `identity` and
`_security_context`. The identity resolver handed to `ModelBindingMiddleware` loses its
`ServerRequestInterface` parameter.

Companion to
[ADR-0051](0051-the-dispatched-route-is-handed-down-not-read-off-the-request.md) and
[ADR-0053](0053-an-attestation-names-the-dispatch-it-was-minted-for.md): those two
settled which ROUTE an authorization decision is about. This one settles which CALLER.

## Context

An authorization decision on a bound route has exactly two inputs the framework
supplies: the route, and the caller. ADR-0051 established that the route cannot be a
request attribute, for reasons that apply verbatim to the caller — and the caller was
one anyway.

`ModelBindingWiring::authenticatedIdentity()` read `_identity`, then `identity`, and
took the first `IdentityInterface` that answered `isAuthenticated()`; failing that, it
resolved through a `SecurityContext` read from the `_security_context` attribute.
`AuthenticationMiddleware` read the `identity` attribute too, and preserved an
already-authenticated one instead of building the context it would otherwise build.

### What a written attribute bought

All three names are PSR-7 request attributes. `ServerRequestInterface::withAttribute()`
is public, `Pulsar\Auth\Identity\Identity` is `#[Api]` with a public constructor, and
`SecurityContext` is `final` but takes any `AuthManagerInterface` — so forging any of
the three needs no reflection and no privilege. Every frame in the pipeline can write
them: application middleware, an extension, a route middleware alias, anything
prepended to the `PostRoutingPipeline`, and — because `AuthenticationMiddleware` read
the attribute as an INPUT — any globally piped frame ahead of AuthWiring's position in
the wiring list.

So "who is calling" was decided by whichever frame wrote last, and the
`AuthorizationHookInterface` was answering a question that frame had set up. Roughly
twenty-five extension classes read these attributes, which is why they cannot simply be
removed; what has to change is which direction they flow.

### The dev server was the proof, not the exception

`pulsar serve --dev-identity` worked by writing `identity` before the kernel ran and
relying on `AuthenticationMiddleware` to believe it. That is the same mechanism an
attacking frame would use, differing only in intent — which is not a distinction the
framework can see. Its existence is the argument: a channel whose legitimate use and
its abuse are indistinguishable is not a channel.

### Why the container is not simply the answer

`AuthManagerInterface` from the container is authoritative, and
`AuthManager::authenticate()` runs the whole guard chain on every call without
memoising. `SecurityContext` is the memo. Reading the manager directly on each bound
route would therefore double the session read or the token verification on every
`auth`-guarded bound route — measured as two guard passes per request where there
should be one.

## Decision

**Authentication publishes its result into a framework-owned holder, and every
authorization decision the framework makes on a bound route reads it from there.**

`Pulsar\Auth\AuthenticationState` holds the request's `SecurityContext`, keyed by
`Fiber::getCurrent()` (or a stable root key) in a `WeakMap` — the shape
`Pulsar\Context\RequestContextHolder` already established. The globally piped
`AuthenticationMiddleware` is the only framework frame that writes it; it publishes the
same instance it attaches as `_security_context`, so the memo is shared and the guards
run at most once per request however many frames ask.

Three consequences of that decision are load-bearing rather than incidental:

- **The identity resolver takes no argument.** `ModelBindingWiring::identityResolver()`
  returns `Closure(): ?IdentityInterface`, closed over the holder. There is no request
  to read an attribute off, which makes the property structural rather than a
  convention that has to be kept.
- **`_identity` and `identity` become an output.** They are still written, for the
  extension controllers that read them, and they are never read back to decide who is
  calling.
- **The holder is request-reset.** `RuntimeWiring` registers it with the
  `RequestResetRegistry`, and that registration is not optional: a resident worker
  reuses one key for every request it serves, so a publication left behind is the next
  caller's identity. That is a worse defect than the one being fixed, and it is the
  reason the holder is Fiber-keyed rather than a plain field even though ADR-0010
  guarantees sequential request handling today. The Fiber key costs nothing under that
  guarantee and is already correct if it is relaxed — unlike `BindingProvenance`'s
  process-wide pass counter, which is the thing ADR-0010's sequential guarantee is
  actually load-bearing for.

**Anything that knows the caller before the guards do says so through the holder.**
`SecurityContext::established()` seeds a context with an answer, and
`DevServerBootstrap` publishes one before `Kernel::handle()` runs. That needs the
booted container, which only the composition root has — which is exactly the
distinction between this channel and an attribute, and the reason `--dev-identity` is
no longer the same mechanism an attacker would use.

**With no auth stack there is no holder and no resolver.** No caller is ever
identified, a regulated preset refuses every bound route, and `ModelBindingWiring`
declares `AuthenticationState` as an `OptionalBinding` so the wiring-contract inspector
reports it as a degraded feature rather than leaving an operator to discover a 401
storm.

## What this is not

It is not a capability, and the docblocks must not say it is. `AuthenticationState` is
`#[Internal]`, which `scripts/boundary_check.php` enforces — no extension may import
it, and the composition root, which may, is where an application already chooses the
`AuthManagerInterface` and the `GateInterface` the whole stack runs on. Anything
holding the container can replace those, and no arrangement of this class changes that.
What it closes is the case the attribute made trivial: a frame INSIDE the request
pipeline, with no container access, naming the caller.

## Consequences

Measured on the composed pipeline, 2,000 requests per case, regulated (`banking`)
preset, Xdebug off:

- An application middleware writing `_identity`, `identity` and a forged
  `_security_context` while nobody is signed in: 401 × 2000, zero resolver calls, the
  Gate asked zero times.
- The same middleware while `user-9` is signed in: 200 × 2000, the hook asked about
  `user-9` and only `user-9`, `ResolutionContext::$subjectId` `user-9` and only
  `user-9`.
- Guard passes: `AuthManager::authenticate()` called 2,000 times for 2,000 requests
  under the global shape, and 2,000 times for 2,000 requests under the global shape
  plus the `auth` alias — one per request, not two.
- Channel cost: 286-301 ns per call against 237-262 ns for the attribute scan it
  replaces, one call per bound request — about 0.16% of a 27.9 µs bound-route request.

`AuthenticationMiddleware`'s constructor gains a required parameter, which is a
breaking change for any composition root that builds it by hand. It is `#[Api]`-unmarked
and absent from the public API snapshot, and AuthWiring is the only construction site
in the framework.

## What this does not close

Five framework frames still take the caller from the `_security_context` request
attribute rather than from the holder: `AuthorizationMiddleware`,
`TwoFactorMiddleware`, `StepUpMiddleware`, `SensitiveOperationMiddleware` and
`LevelOfAssuranceMiddleware`. Today the attribute and the holder carry the same object,
because `AuthenticationMiddleware` writes both from one construction — but they agree
by construction, not by enforcement, and a frame that replaces the attribute separates
them. The binding layer keeps the framework's answer; those five take the replacement.
Moving them is a one-line change each and is deliberately not made here, because
`AuthorizationMiddleware` is being restructured by ADR-0052 and ADR-0053 in the same
cycle. Until it is made, "the binding layer is safe" must not be read as "the request
attribute is no longer an authorization input anywhere".
