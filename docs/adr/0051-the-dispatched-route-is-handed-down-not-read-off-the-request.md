# ADR-0051: The dispatched route is handed down, not read off the request

## Status

Accepted. Adds one `#[Api]` interface
(`Pulsar\Http\Middleware\DispatchedRouteAwareInterface`), one optional parameter to
`MiddlewarePipeline::dispatch()` and `PostRoutingPipeline::dispatch()`, and one
private helper on the kernel. `ModelBindingMiddleware` stops reading the `_route`
request attribute. Continues
[ADR-0044](0044-handler-arguments-are-resolved-not-spread.md), which established
that a handler argument is claimed on evidence rather than spread from a request,
into the frame that produces that evidence.

## Context

`Pulsar\Core\Kernel::dispatchRoute()` matches a route and writes the resulting
`MatchedRoute` to the `_route` request attribute. It then dispatches the route's
handler — from the `MatchedRoute` it closed over, not from the attribute. Nothing
below that line reads the attribute back: `invokeHandler()` uses `$matched`, and
`resolveHandlerArguments()` is given `$matched->parameters`.

`ModelBindingMiddleware` read the attribute.

### Everything the binding layer decides is a property of the route

Which parameters name a model, which class each resolves to, whether the route
declares the `_without_authorization` opt-out, whether it is `RouteAccess::Public`,
how a nested child is scoped to its parent — all of it is read off the route, and
the output is a set of models `BoundModelArgumentResolver` hands to the handler as
`SealedArgument`s: vetted values that an ordinary resolver claim cannot displace.

So the route is the input to an authorization decision, and the attribute carrying
it is ordinary request surface. Between the kernel's write and the middleware's read
sit route-level middleware, everything piped into the `PostRoutingPipeline`, and —
because that pipeline is container-bound and its `prepend()` is public — anything an
extension chooses to put in front of the binding middleware. `MatchedRoute` and
`Route` are both `#[Api]` with public constructors, so forging one needs no
reflection and no privilege.

### What a rewritten attribute bought

Not a different handler: the kernel goes on serving the route it matched. What it
bought was a different set of RULES applied to the request that is being served.

The forgery only has to copy the real route's parameter names and values, because
that is all the downstream checks look at — `BindingProvenance` attests to
`(object, parameter name, raw URL value)` and `BoundModelArgumentResolver` validates
that attestation against the real route's parameters. Everything else about the
route is the attacker's to choose. Naming a neighbouring route that declares
`_without_authorization` and is declared public — a legal exemption on that route —
turned a request the policy hook refuses into a model resolved with no gate at all,
minted, and sealed onto the real handler's entity-typed parameter.

Two things about that are worth stating plainly. The seal was not bypassed; it was
issued. And the deployment that is most exposed is the correctly configured one:
`ModelBindingConfig::DEFAULT_PRESET` is a regulated preset, so the routes that
mandate authorization are exactly the routes an exemption is worth forging.

### Why "check that they agree" is the wrong shape

The obvious repair is to compare the attribute against the dispatched route and
refuse a mismatch. That is a second thing to keep in step with the first, on a path
where the cost of the branch being wrong is an authorization bypass. It also leaves
the attribute as the channel, which means every future middleware that needs the
route inherits the same problem and the same obligation to remember the check.

## Decision

**A middleware whose decisions belong to the route is handed that route by the
kernel. It does not read `_route`.**

`Pulsar\Http\Middleware\DispatchedRouteAwareInterface` is the protocol:

```php
public function forDispatchedRoute(MatchedRoute $route): self;
```

`Kernel::dispatchRoute()` passes the dispatched `MatchedRoute` as an argument to
both pipelines it runs — the route-level one and the `PostRoutingPipeline`. Each
pipeline, while building its chain, replaces every middleware implementing the
interface with `$middleware->forDispatchedRoute($route)`. The binding happens
before the chain exists, so it happens before any frame that could rewrite an
attribute exists.

Three properties fall out of that shape rather than being enforced:

- **There is no second value.** `ModelBindingMiddleware::process()` reads a private
  property the pipeline wrote. `_route` is not an input to a binding decision, so a
  rewritten `_route` reaches nothing.
- **Binding returns a copy.** Middleware are resolved once and reused for the
  process lifetime, and a persistent worker interleaves Fiber-suspended requests
  through the same instance. A route stored on the shared object would be another
  request's route, so `forDispatchedRoute()` must return a new instance and is
  marked `#[\NoDiscard]` — dropping the return value leaves the unbound instance in
  the chain.
- **Unbound is inert, not guessing.** A middleware nothing dispatched — piped into
  the global pipeline, which runs before routing, or dispatched by a pipeline that
  was not told the route — has no route and hands the request on, exactly as it does
  for a route with no parameters. Nothing is invented from the request.

The interface is `#[Api]`, not `#[Internal]`. An extension middleware whose decision
belongs to the route has precisely the same problem, and a protocol only the
framework could implement would leave every extension reading the rewritable
attribute while the framework read the safe one. This codebase does not grant itself
privileged access (ADR-0004).

**The kernel also restores its own value.** `_route` remains ordinary request
surface, and plenty of code legitimately observes it — controllers, argument
resolvers, observability. A frame that rewrote it was telling all of them that a
route which is not being served is being served, and that claim is false by
construction. So the kernel puts the dispatched route back at the two boundaries it
owns: entering the post-routing pipeline, which undoes anything route middleware
did, and entering the handler frame, which undoes anything the post-routing stack
did. The comparison is by identity, so on every untampered request no clone is made.

That restoration is a floor, not the mechanism. Between those boundaries one
middleware can still rewrite the attribute for the next, which is exactly why a
middleware deriving AUTHORITY from the route takes it through the interface instead.

## Consequences

- `MiddlewarePipeline::dispatch()` and `PostRoutingPipeline::dispatch()` take an
  optional third argument. Existing callers are unaffected; passing nothing leaves
  route-aware middleware unbound, which is the correct answer everywhere except a
  kernel dispatch.
- `Pulsar\Http` names `Pulsar\Routing\MatchedRoute`. That edge is already permitted
  by the layer ruleset and is the same direction `RateLimitMiddleware` and
  `AuthorizationMiddleware` already take.
- Tests that drove the middleware by putting a `MatchedRoute` on the request now
  bind it explicitly. The change is mechanical, and it makes the harnesses say which
  route is being dispatched rather than implying it.
- One allocation per dispatch per route-aware middleware. The resolved instances
  stay memoised; only the bound copy is per-request.

## What this does not close

- **The attestation still does not name the route.** `BindingProvenance` keys on
  `(object, parameter name, raw URL value)`. Nothing in this ADR changes that; what
  changes is that the only party who can mint an attestation is now working from the
  dispatched route. If a second minting path is ever added, this property has to be
  re-argued rather than assumed.
- **`_route` is still writable between the kernel's two restoration points.** A
  middleware that misleads the frame after it about which route is being served can
  still do so. That is a correctness problem for whatever reads it, not an
  authorization one, and the interface is the answer for anything in the second
  category.
- **Other readers of `_route` have not been moved.**
  `Pulsar\Auth\Middleware\AuthorizationMiddleware` and
  `Pulsar\Http\Middleware\RateLimitMiddleware` are route-level middleware and still
  read the attribute. They run outside the post-routing pipeline, so the kernel's
  restoration does not cover the case of one route middleware rewriting it for
  another. Moving them onto the same channel is a separate change with its own
  behaviour surface.
