# ADR-0053: An attestation names the dispatch it was minted for

## Status

Accepted. Continues
[ADR-0051](0051-the-dispatched-route-is-handed-down-not-read-off-the-request.md)
into the two places it left open: the record the seal rests on, and the three
other route-level middleware whose decision is a property of the route.

Changes `BindingProvenance::record()` and `::attests()` to take the
`MatchedRoute` the mint was made under (both `#[Internal]`).
`BoundModelArgumentResolver` reads the dispatched route at the handler frame.
`AuthorizationMiddleware`, `RateLimitMiddleware` and
`SensitiveOperationMiddleware` implement `DispatchedRouteAwareInterface` and stop
reading `_route`. An unauthenticated
refusal in `AuthorizationMiddleware` increments a counter instead of writing to
the audit chain. No `#[Api]` signature changes.

## Context

ADR-0051 moved the route off the request attribute and into an argument, so that
`ModelBindingMiddleware` decides from the route the kernel is dispatching. It also
named, in passing, the reason a forgery had worked at all:

> `BindingProvenance` attests to `(object, parameter name, raw URL value)` and
> `BoundModelArgumentResolver` validates that attestation against the real route's
> parameters. Everything else about the route is the attacker's to choose.

Handing the route down closed the channel a forgery had used. It did not change
what an attestation says, and the seal is still issued on a triple that a forgery
can copy exactly. Three things follow.

### The middleware is reachable, and hiding it was tried twice

`forDispatchedRoute()` is public because `DispatchedRouteAwareInterface` requires
it. `process()` is public because PSR-15 requires it. `ModelBindingWiring` binds
the middleware in the container, and unbinding it changes nothing: the
`PostRoutingPipeline` is itself container-bound and its `snapshot()` is public, so
anything piped into it is handed back on request. `Closure::bind()` reads a private
property of any object that can be reached at all, so keeping `ModelBinder` and
`BindingProvenance` out of the container removes the shortest mint and no more.

Measured end-to-end through `Kernel::handle()`, with the binding middleware
resolved from the container by a frame piped after it, bound to a neighbouring
route declaring `_without_authorization` on a `#[PublicRoute]` handler, and given a
match carrying the real route's parameter name and value:

|                                                     | before                                 | after                  |
| --------------------------------------------------- | -------------------------------------- | ---------------------- |
| authorization hook calls for the substituted model  | 0                                      | 0                      |
| model reaching the handler's entity-typed parameter | the attacker's                         | none                   |
| response                                            | 200, handler ran on an unvetted object | 500, handler never ran |

The hook was never asked, and would not have been: the route the attacker chose
legally exempts itself. Nothing about visibility can close that, because both
methods have to be public for the interface and the PSR to work.

### Three more middleware were reading the attribute

`AuthorizationMiddleware` is registered as the `auth` alias, so it runs in the
route-level pipeline — which `Kernel::dispatchRoute()` wraps _around_ the
post-routing pipeline. Measured, on a route registered through
`RouteAccessRegistrar::authenticated()`, the order is `auth`, then everything piped
post-routing, then the handler. It is the outermost authorization decision on such
a route, and other route middleware runs in front of it.

It read the required permissions from `_route`. A frame ahead of it could hand it a
route declaring `permissions: ['_authenticated']` — the documented "any
authenticated user" sentinel — and the real route's permissions were never
consulted. Measured: gate calls 0, handler ran, HTTP 200, on a route declaring
`admin.super`.

`RateLimitMiddleware::resolveRoute()` guarded `_route` with `is_string()`. The
attribute holds a `MatchedRoute` object, so the guard was false on every request
that ever reached it and every route-scoped bucket silently degraded to method +
path. Measured: three ids on one route produced three buckets. A route-scoped limit
was evaded by varying the id, and the bucket count the limiter store held was
bounded by the URL space rather than by the route table.

`SensitiveOperationMiddleware` — the `sensitive` alias, also route-level — read
the `sensitive_operation` declaration from `_route`. The bypass there needed no
forged declaration at all: a substituted route that simply OMITS the attribute made
the lookup return null, and the account-takeover guard, the re-authentication
window and the audit entry were all skipped for an operation the real route
declares.

Replacing a dead guard with a live read would have swapped a broken control for an
evadable one. All three frames are route-level, so all three have other route
middleware in front of them.

A grep for `getAttribute('_route')` across `src/` now returns four sites and none
of them derives authority from it: the kernel's own identity comparison in
`restoreDispatchedRoute()`, `BoundModelArgumentResolver` reading it at the handler
frame where the kernel has just restored it, and two comments saying why it is not
read.

### The chain write that actually runs on a regulated route

`ModelBindingMiddleware` aggregated its anonymous denials behind a ceiling. On the
canonical `authenticated()` bound route that code never runs: the caller is refused
one frame out, by `AuthorizationMiddleware`, which wrote a full audit entry with no
ceiling of any kind and `resource` set to the requested path.

Measured, 200 anonymous requests to one bound route: 200 chain entries, 200
distinct `resource` values, 424 bytes per request, 708 µs per request. Every entry
carried actor `anonymous`, action `authenticate`, reason `unauthenticated`. The
only column that varied was the one the caller chose.

## Decision

### 1. An entry in `BindingProvenance` names the dispatch it was minted under

`record()` takes the `MatchedRoute` the binder was resolving for and stores it
beside the pass counter and the `(parameter, value)` set. `attests()` takes a route
and answers only when it is that same object, by identity.

The route the question arrives with is the kernel's own.
`Kernel::dispatchRoute()` restores its `MatchedRoute` onto the request at the
handler frame, undoing whatever the post-routing stack wrote, and then resolves the
handler's arguments from that request — so `BoundModelArgumentResolver` reading
`_route` _there_ reads the dispatched route and nothing else. That is the one frame
in the request where the attribute is not ordinary surface. Router matching
allocates a fresh `MatchedRoute` per match, so object identity means "this
dispatch".

Minting under a route of one's own choosing is still possible and now buys nothing.
Minting under the kernel's own route buys exactly what that route already grants,
decided by that route's preset, opt-out and hook.

### 2. The residue is the caller, and it is not this ADR's to close

A frame that can call the middleware can also hand it a request carrying an
identity of its choosing, because the binding layer takes the caller from a request
attribute. Measured on the same harness: with identity read from the attribute the
attacker's own model reaches the handler; with identity taken from a channel the
framework owns, the same attack yields the victim's model — the binding the request
already earns.

So "the handler's declaration decides" has two halves and this ADR closes one. The
other is closed by moving identity off the request. Neither is sufficient alone and
the two together are.

### 3. Every route-level middleware whose decision is the route's takes it as an argument

`AuthorizationMiddleware`, `RateLimitMiddleware` and `SensitiveOperationMiddleware`
implement `DispatchedRouteAwareInterface`. All three are `final readonly`, so the
per-dispatch copy is a construction rather than a `clone`.

An unbound copy keeps the answer it already had for a request with no route:
`AuthorizationMiddleware` fails closed with a 403, `SensitiveOperationMiddleware`
names no operation, and `RateLimitMiddleware` falls back to method + path, which is
the honest key for a request whose route is not decided. The route-scoped bucket key is the route's name when it has one and its
pattern otherwise — both from the route table, which is what makes the number of
buckets a route-scoped strategy can create the size of that table.

### 4. An unauthenticated refusal is counted, not chained

`AuthorizationMiddleware` increments `pulsar_auth_anonymous_denials_total`,
labelled `(route, reason)`, and logs the occurrence at `debug`. Three arguments,
each of which the old entry made itself:

- **Nothing was accessed.** The refusal is decided from the identity alone, before
  any permission is evaluated and before any handler runs.
- **The key space belonged to the caller.** `resource` was the request path, so the
  number of distinct entries was the size of the URL space.
- **Every entry was the same entry.** Same actor, action and reason; a thousand of
  them carry what one carries.

The label set is bounded by the route table for the same reason the resource column
must not be the path: `LabelSet` keys an in-memory map per distinct combination, so
labelling on the path rebuilds in memory exactly the unbounded key space the chain
write had on disk.

A denial that names an actor is unchanged: full entry, one per occurrence, no
ceiling, no aggregation. Its volume is bounded by the number of credentials and
whoever floods it is named in every line.

Measured after: 200 anonymous requests to 200 distinct paths → 0 chain entries, 0
bytes, one counter series, 42 µs per request against 708 µs before.

### 5. A ceiling is not a mechanism

The aggregation ceiling this replaces could be filled — 64 distinct
`(route, reason)` shapes and no further anonymous denial was recorded at all, which
is an audit-suppression switch reachable without credentials. A counter has no
ceiling to fill because it stores one number per label combination and the label
combinations come from the route table.

With no metrics registry wired the counter is absent and the `debug` line is what
remains. Metrics are on by default and `MetricsWiring` runs before `AuthWiring`, so
the binding is final when the middleware is built; an operator who turns metrics
off loses the count, and the access log still counts the 401. `AuthWiring`
publishes no `WiringContract`, so that degradation is documented here and in
`docs/authorization.md` rather than reported by the wiring-contract inspector —
giving `AuthWiring` a contract is a larger change than this ADR makes, and
claiming the inspector covers it would be a claim the code does not support.

A metric must never be able to turn a refusal into a 500: the increment is wrapped
and a failure is logged at `warning`.

## Consequences

- `BindingProvenance::record()` and `::attests()` take one more argument. Both are
  `#[Internal]`; the public `ModelBinder` signature is unchanged.
- A hostile in-process frame that opens a second binding pass invalidates the
  legitimate attestation as well as failing to forge one, so the handler receives
  nothing and a controller typed on the entity fails loudly. That is the direction
  to fail in, and it is unchanged from before this ADR — the pass counter already
  had that property.
- `BindingProvenance` holds one `MatchedRoute` reference per live model, replaced
  whenever that model is minted again. Bounded by resident models, not by requests.
- Tests that drove `AuthorizationMiddleware` or `RateLimitMiddleware` by writing
  `_route` now bind the route instead. Three of them had been passing on the
  fail-closed branch rather than on the decision they named.
- `AuthenticationFlowTest` asserted the chain entry this ADR removes. It now
  asserts the counter, that eleven caller-chosen paths are one series, and that the
  credential still reaches neither channel.
- A credential that was PRESENTED and REFUSED is counted here rather than chained,
  and this frame cannot tell it apart from a request that presented nothing — it
  sees only `isAuthenticated() === false`. If that distinction should reach the
  chain, the record has to come from the guard that saw the credential, where the
  actor and the key space are bounded. That is not a decision this frame can make.
