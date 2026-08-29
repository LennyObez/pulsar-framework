# ADR-0044: Handler arguments are resolved, not spread

## Status

Accepted. Changes how the kernel invokes every controller in every application, and what a
nested route path means, which [ADR-0001](0001-architecture-decision-records.md) requires an
ADR for. Adds four types under `#[Api(since: '1.0.0-rc.11')]` in `Pulsar\Core\Controller` and
two more in `Pulsar\Routing\Binding` (`BindingScope`, `BindingPreset`) — additive, as the RC
phase prefers.

**Two changes are not additive and are recorded as such.** Both are the same shape — an
`#[Api]` interface method growing a parameter, which every _call site_ survives and every
external _implementor_ does not — and both were taken because the alternative left a
security-relevant declaration reachable through a second, quieter path.

`RouterInterface::model()` — `#[Api]` since 1.0.0 — gained two parameters
(`BindingScope $scope`, `?string $parentRelation`). Both have defaults, so every call site is
source-compatible, but PHP requires an implementation to match its interface, so any
third-party class implementing `RouterInterface` with the old three-parameter `model()` is a
fatal error until it adopts the new signature. The alternative was a second method
(`modelScoped()`), which would have left `model()` as a way to declare a binding while saying
nothing about its scope — two entry points where the security-relevant one is the longer
name. Accepted knowingly in the RC phase, where the population of external `RouterInterface`
implementors is the two in this repository.

`FrameworkCacheInterface::warm()` — `#[Api]` since 1.0.0 — gained a trailing
`array $bindings = []`, so that the declarations `Router::model()` made are written into the
route cache alongside the routes they qualify. The alternative was to add the parameter to
the concrete `FrameworkCache` only (PHP permits an implementation to add optional parameters)
and have `pulsar optimize` narrow to the class. That keeps the published signature untouched
and makes the contract a half-truth: an alternative implementation would satisfy the
interface while silently storing a route table whose scope declarations had gone — the same
defect this ADR's escape hatches exist to prevent, one layer down. The interface is the
contract, and the contract now includes the declarations.

Continues the argument of
[ADR-0041](0041-the-token-vault-takes-a-connection.md): a capability that reports itself
implemented on the strength of code existing is worth less than no capability at all.
Keeps the port/adapter split [ADR-0002](0002-modular-monolith-vertical-slices-ports-adapters.md)
mandates — `ModelResolverPort` stays in routing, the ORM adapter stays in the extension —
by never letting the kernel learn what a bound model is.

## Context

Route model binding was documented over 260 lines, carried `#[Api]` on seven types under
`src/Routing/Binding/`, and had never resolved a model in any application. It was broken at
five independent points, four of which are the subject of this ADR — the fourth being the
first repair of the third, which reintroduced it in a form that was harder to see.

**It was never constructed.** Nothing in `src/Core/Wiring/` built `ModelBinder`,
`BindingResolver`, `ModelBindingMiddleware` or `PolicyAuthorizationHook`. Every one of
them existed only inside its own unit test. The documentation described a request pipeline
that no boot assembled.

**The scoped-binding gap was an authorization bypass, and it was reproduced.**
`BindingResolver::resolveImplicit()` constructed each `BindingMeta` with the key name
`id` and never set `scoped` or `parentRelation`. `ModelBinder`'s scoped branch is gated on
`$meta->scoped && $previousModel !== null && $meta->parentRelation !== null`, so an
implicit binding could not enter it under any input. Against a real database, on the route
`/users/{user}/posts/{post}`, a request for `/users/1/posts/20` where post 20 belongs to
user 2 returned that post — while the URL, the controller signature and the documentation
all said the read was constrained to user 1. The ORM adapter's `resolveScoped()` was
correct and its security properties were mutation-verified; it was dead code, because
nothing ever asked it to scope.

**The first fix for that bypass reproduced it, because it made scoping depend on the
controller's signature.** `applyPathMetadata()` walked the path marking a placeholder scoped
only when an _earlier_ placeholder already carried a `BindingMeta`, and a placeholder with no
`BindingMeta` was skipped without disturbing that state. Binding metadata came from reflecting
the handler, so the effect was that the handler's parameter list decided which ownership
checks ran. Two shapes, both reproduced against a real database and a real booted kernel:

- `/users/{user}/posts/{post}` handled by `show(Post $post)`. The handler does not need the
  user, so nothing declared `{user}`, so `{post}` was resolved **unscoped**.
  `/users/1/posts/20` returned user 2's post — the original bypass, through a new door.
- `/users/{user}/posts/{post}/comments/{comment}` handled by `show(User $u, Comment $c)`.
  `{post}` carried no metadata and was skipped, so `{comment}` was re-parented to `{user}`
  through a same-named `comments` relation and the request returned **200, looking fully
  validated**. That is worse than resolving unscoped: an unscoped read is at least visibly
  unchecked, while this one produced a scoped query against the wrong ancestor.

The fault is stated once and it is not a missing guard. What a controller asks for is a
convenience; what the URL asserts is a security boundary. Coupling the second to the first
means every future change to a handler signature is a change to an authorization decision, and
no amount of care at the call site makes that safe.

**Wiring the middleware alone would have produced a 500, not a feature.**
`Kernel::invokeHandler()` spread the raw string route parameters positionally and never
read the `_bound_models` attribute the middleware sets. A controller declaring
`show(User $user)` would have been handed the string `"1"` and died with a `TypeError` at
the call site. The state of the tree was that binding was silently absent; wiring it
without changing invocation would have converted that into a hard failure on every bound
route. The two changes are one change.

Two further defects — `{param:key}` excluded from the path patterns in `Route` and
`RouteCompiler`, and `ResolutionContext::$includeTrashed` unreachable because the
middleware never populated it — are fixed in the same work and decide nothing
architectural. They are recorded in `docs/route-model-binding.md`.

## Decision

**1. The kernel asks resolvers for handler arguments instead of spreading route
parameters.** `Pulsar\Core\Controller` gains a contract:
`HandlerArgumentResolverInterface` supplies values for named parameters,
`HandlerSignature`/`HandlerParameter` describe the handler as plain data, and
`ArgumentResolverRegistryInterface` is the registration side. `Kernel::resolveHandlerArguments()`
consults the WHOLE chain first and merges the claims, then falls back to the ladder it has
always applied: the route parameter of the same name, then the declared default. A resolver
states both facts in one value — the keys it returns are the parameters it claims, the values
are what it supplies — so a claim of `null` is distinct from no claim. Resolvers are consulted
in registration order and, for an ordinary claim, the first claim on a name wins. Decision 1b
covers the claims for which order must decide nothing.

**1b. A claim carries whether it may be displaced. `SealedArgument` is the mechanism, and
registration order is not.** A resolver that wraps its value in
`Pulsar\Core\Controller\SealedArgument` displaces an ordinary claim made before it and blocks
every ordinary claim made after it, so the value reaches the handler wherever on the chain its
resolver sits. Two seals on one parameter are refused —
`ConflictingSealedArgumentException`, and the request fails closed — because preferring the
earlier seal would reintroduce ordering at the exact point sealing removes it.

The alternative considered was a privileged earlier STAGE for framework resolvers. It was
rejected on the grounds that the property being protected belongs to the VALUE, not to its
producer: the bound model is protected because an authorization hook approved it, not because
a framework class returned it, and an application or extension resolver returning the subject
of a verified signature is in exactly the same position. A stage scheme would also still
resolve contests by position — coarser, but position — and would force the framework to
classify every resolver as privileged or not, which is the burden this decision removes. And
it makes the double-authority case detectable: two seals is a refusal, where a stage scheme
would silently pick whichever stage ran first.

Sealing had to replace ordering rather than supplement it because the framework cannot win on
order. `ModelBindingWiring` registers `BoundModelArgumentResolver` from `DeferredComposition`
(decision 3), which the kernel drains at the END of boot — after extension `register()`, after
extension `boot()`, after the project route files. Every application and extension resolver is
therefore registered AHEAD of it, and under first-claim-wins the framework's resolver always
loses a contested name. Every claim `BoundModelArgumentResolver` makes is consequently sealed.

**1c. A seal asserts where the value came from, so provenance is part of what is sealed.**
`SealedArgument` says "this value must not be displaced". That is a claim about a decision the
displacing resolver cannot see, so the sealing resolver has to be able to point at the decision.
`BoundModelArgumentResolver` could not: its whole evidence was the `_bound_models` request
attribute, which every middleware inner to the binding one can write. Sealing on that turned
"cannot be displaced" into "was authorized" — a stronger claim than anything upstream had made,
on the parameter whose entity type hint is what makes the route read as safe.

`Pulsar\Routing\Binding\BindingProvenance` is the record that closes it. `ModelBinder` writes
one entry per model it resolves — the instance, the route parameter it was resolved for, and the
raw URL value it was resolved from — and mints a level only after the authorization gate it was
given has passed it, so a model the hook refused is never recorded. `BoundModelArgumentResolver`
seals only what that record attests. `ModelBindingWiring` composes both halves from a single
instance and binds it in no container, so the write side is reachable only through a private
property of the binder. A model with no entry is not claimed at all,
sealed or otherwise: the parameter falls through to the kernel's route-parameter and default
ladder, because handing a handler an unvetted object on an entity-typed parameter is the outcome
the seal exists to prevent rather than a lesser version of it.

Binding the entry to the parameter AND the URL value, rather than recording "the framework made
this object", is what makes a replayed instance worthless. An object the binder produced for
`{post}` = 41 cannot be presented for `{post}` = 9, nor for a different parameter of the same
route. What remains replayable is the object the current request would resolve anyway — and a
request only reaches a handler after its own binding pass resolved and authorized that same
parameter and value, so the replay wins nothing that was not already granted.

**1d. The chain's baseline is a capability the kernel holds, not a method on the chain.**
`ArgumentResolverChain` is published in the container so a wiring can `add()` to it. Restoring
its resolver list — which `Kernel::shutdown()` needs, or a re-booting worker stacks a second copy
of every resolver — was published on the same object, and it is a strictly larger power than
adding: an appended resolver is judged by the merge rules and refused a sealed name, while a
replacement removes the sealing resolver and with it the seal. Anything that could reach the
container could therefore take a bound route's parameter with an ordinary claim.

`ArgumentResolverChain::issueLifecycle()` now hands out one `ArgumentResolverLifecycle` — a
handle carrying the snapshot and restore closures — and refuses every request after the first
with `ArgumentResolverLifecycleException`. The kernel takes it in its constructor, before any
wiring, extension or route file runs, so there is nothing left to issue by the time anything
else could ask. The handle is held in a private kernel property and bound in no container. The
rejected alternative was to keep the method and check the caller, which cannot be done in PHP
without reading the stack, and would have made the guard a heuristic instead of an object nobody
else was given.

**1a. Fillability is decided once, after the merge. A parameter that cannot be filled is
still omitted; what changes is where the remaining values land.** A resolver sees the
signature, the request and the route parameters; it cannot see the chain, so it cannot know
whether the parameter it just declined is supplied by the resolver after it. No resolver may
therefore reason about a parameter other than the one it is claiming — the contract says so,
and the kernel is the only place holding every claim.

Two questions hide inside "what does the kernel do about a parameter nothing can fill", and
the first revision of this decision answered them as one. WHICH parameters get a value is
unchanged and stays unchanged: the unfillable one is omitted and the kernel keeps going,
exactly as it has since the framework's first release. WHERE each value lands is the part
that had to change, and only in the case where it must.

Omitting a parameter shifts every later argument one slot left. While every value came from
the route or from a declared default, that shift is what the framework always did, and
applications are written against it. The moment a RESOLVER's value is in the list the same
shift becomes a misdelivery — a value computed for one parameter arriving at another, which
on a bound route means an authorized entity landing in a slot declared for something else,
or the raw identifier from the URL landing where the entity was declared. So the kernel
chooses its delivery mode:

- POSITIONAL — a plain list, byte-identical to the pre-chain kernel — whenever no claimed
  value entered the argument list, and whenever nothing was omitted ahead of a value that
  did. In the second case both modes produce the same call, so the cheaper one is used.
- BY NAME — the same values, spread as named arguments — when a claimed value is in the list
  and an omitted parameter precedes some value. Every value lands on the parameter it was
  computed for, and the omitted parameter raises an `ArgumentCountError` naming itself rather
  than silently receiving its successor's value.

The invariant this buys, and the one to check any future change against: **when no claimed
value reaches the argument list, both the values and the delivery mode are the ones the
kernel used before the resolver chain existed.** A claim is delivered to the parameter it
names or not at all — never to a different one.

The rejected answer was to stop building the list at the first unfillable parameter, which is
what the first revision of this ADR specified and what shipped in the first revision of the
code. It reads as the strictly safer option and it is not: it converted a shape thousands of
routes are written around into a hard 500. Measured differentially against the previous
kernel — HEAD's `src/` extracted with `git archive`, one identical harness driven through
`Kernel::handle()` against each tree, isolation checked by reflecting the loaded `Kernel`'s
file path — **eleven** ordinary handler shapes went from 200 to 500. Not one of them involved
a resolver, a bound model or any part of this decision.

**2. Bound models are one contributor to that chain, not a kernel concept.**
`BoundModelArgumentResolver` reads `_bound_models` and claims a parameter only when the
resolved object satisfies its declared class. The kernel knows a name was claimed and a
value came back; it does not know what a model is, and it does not import anything from
`src/Routing/Binding/`.

The alternative was three lines in `invokeHandler()` reading `_bound_models` directly. It
was rejected for two reasons. The kernel is the one place every request passes, so each
subsequent injectable — a service, a request object, a validated payload — would be
another edit to it, and route model binding would have been the first of them written in a
form only route model binding could use. And core would have acquired the vocabulary of
one optional feature in its invocation path, which is the dependency ADR-0002 exists to
prevent, expressed as an attribute name rather than an import.

**3. Two composition-root seams the decision required.** `PostRoutingPipeline` is
dispatched innermost, inside route middleware: that is the only position simultaneously
after routing (so `_route` exists) and after every frame that can authenticate, the global
`AuthenticationMiddleware` and the route-level `auth` alias. The weaker verb is deliberate:
only the `auth` alias resolves an identity, while the global middleware attaches a lazy
`SecurityContext` and an anonymous placeholder, so position buys the opportunity to know the
caller and the middleware has to take it. `DeferredComposition` defers
a wiring's gate to the end of boot, because both facts route model binding depends on are
false while the wiring loop runs — `ModelResolverPort` is bound by an extension's
`register()`, and `Router::$explicitBindings` is filled by the project route files, both
of which the kernel processes after every wiring has finished.

**4. Composition is conditional, and its absence is silent.** `ModelBindingWiring` composes
nothing unless a `ModelResolverPort` is bound and an `AuthorizationHookInterface` exists.
No permissive fallback hook is offered: `AuthorizationMiddleware` in this codebase
default-denies, and a binding hook that default-allowed one frame away would hand every
authenticated caller every model a route names while the route attributes still claimed
the route was authorized.

**5. Containment in the path, never the handler signature.** `BindingResolver` answers two
questions from two sources and never lets one leak into the other. **What a parameter is**
comes from the compiled map, from reflecting the handler, or from `Router::model()`. **How it
is constrained** comes from the route path alone.

The rule, in full:

- A bound placeholder that occupies a whole path segment is contained by the resource
  placeholder **immediately** in front of it, through the literal segment directly in front,
  used verbatim. Never a grandparent — the path parser carries only the nearest resource
  forward, so a grandparent is not a value it can produce and there is no guard to bypass.
- A DECLARED parent is resolved whether or not any handler parameter wants it, because the
  framework needs the object to scope the child by. On `/users/{user}/posts/{post}` handled by
  `show(Post $post)` plus `Router::model('user', User::class)`, the `User` is loaded and
  discarded. Wanting it and declaring it are separate questions and only the second is
  required — which is why the bullet below, not this one, covers the same route without that
  call.
- If nothing declares what the parent is, or no segment names the relation, **the child is not
  resolved**. There is no third outcome in which it resolves unscoped. `/compare/{a}/{b}`,
  `/users/{user}/posts-{post}` and `/users/{user}/posts/{post}` handled by `show(Post $post)`
  alone used to fall through to exactly that outcome and now refuse.

The refusals are 500s rather than 404s because they describe a route the application declared
and cannot serve, and they are decided before any read reaches the resolver.

Two escape hatches, because an application may genuinely mean something the path cannot say,
and both are typed so that neither can be reached by leaving something out or misspelling a
string. `BindingScope::Contained` with a relation name covers a segment no PHP property can be
called after (`blog-posts`) and still resolves and checks the parent.
`BindingScope::Root` says a nested resource is global on purpose; it is the only way to get an
unscoped child, it has to be written down, and it reads as those words in review. `BindingMeta`
rejects any combination of scope, relation and parent that contradicts itself, so an
inconsistent binding is a construction error rather than a downstream interpretation.

`CompiledBindingMap` is the other channel for both, and its serialization was fixed in the same
work: `scope` is now written always, including the default, so "nothing was declared" is
distinguishable from "declared root", and an unrecognized scope value falls back to
`Path` — the containment branch — rather than to anything permissive.

**6. A declaration is part of the route table, so the route cache carries it.** An escape
hatch that exists in development and not in production is not an escape hatch; it is a reason
to stop using the rule it lets out of. `Router::model()` is called from `routes/web.php` and
`routes/api.php`, and a cached-route boot skips those files by design, so `pulsar optimize`
used to erase every declaration an application had written.

The declarations therefore travel with the routes, in one payload — a `CachedRouteTable`
inside `routes.cache.bin`, under one manifest signature and one invalidation key. Two files
would have made "routes from this warm, declarations from that one" a state the system can be
in; one payload makes it unrepresentable. The composition root converts in both directions
(`CachedRouteReconstructor`), which is also what keeps `Pulsar\Cache` from importing
`ExplicitBinding` and the Router from importing a cache DTO — the same separation `CachedRoute`
has always had, applied to the second half of the table.

A payload this build cannot read is discarded WHOLE: an older shape, or a stored declaration
whose scope and relation contradict each other, leaves `$routesCached` false and sends the
boot down the cold path, where the route files run and declare for real. Applying the routes
and dropping the declarations that qualify them would be the original defect in a quieter
register, so it is not an outcome the code can produce.

## Consequences

**What it costs an application that binds nothing.** The design raised the performance
question of a per-parameter resolver chain on the hot path; measured against the code that
landed, an application with no resolver registered pays:

- one `Container::has()` at the end of boot — the gate that decides not to compose;
- one `$this->argumentResolvers->resolvers === []` comparison per handler invocation —
  no method call, no allocation, after which the ladder is the pre-change one;
- one `PostRoutingPipeline::isEmpty()` test per dispatch, after which the handler is called
  through the same closure as before: no pipeline constructed, no stack frame added.

Reflection got cheaper rather than dearer. The three parallel caches (`handlerWantsRequest`,
`handlerUsesArrayParams`, `handlerParamMap`) collapsed into one memoised `HandlerDescriptor`
built from a single `ReflectionMethod` instead of two, memoised per `Class::method` for the
process lifetime. `HandlerParameter` is deliberately `var_export()`-able — no closures, no
reflection objects — so a build step can precompute the whole map and remove boot-time
reflection entirely. Nothing does that yet.

With a resolver registered the cost is one `resolve()` call per resolver per handler
invocation, over that handler's parameter list. `BoundModelArgumentResolver` returns an
empty array immediately when the request carries no `_bound_models`.

The merge itself pays for decision 1b. A resolver that claims nothing is skipped on one `===
[]` comparison, exactly as before; a resolver that does claim now costs an `array_filter` over
its own claims to find the seals, plus an `array_diff_key` for the rest, in place of the single
`+` union. Both walk a map that is bounded by the handler's parameter count — three or four
entries on a real controller — and they are what keeps the merge free of the `mixed` variable
assignment the analysers forbid at this strictness, so the seal is checkable code rather than
suppressed code. The alternative, a per-value `foreach`, would have needed a `MixedAssignment`
suppression on the file for the sake of one avoided allocation.

**Existing handler shapes are unchanged, and pinned by tests.** A closure or other callable
handler still receives `($request, $matched->parameters)` and is not reflected at all — so
a closure route is never model-bound. A handler whose first non-request parameter is typed
`array` still receives the raw route parameters and returns before the chain is consulted;
such a handler cannot be given bound models and must read `_bound_models` itself.
`HandlerInvocationContractTest` enumerates 60 shapes — closures, first-class callables, array
callables both bound and static, invokables, zero parameters, variadics, optional and nullable
parameters, union and intersection types, untyped and by-reference parameters, optional
placeholders, parameters named like a route parameter but typed otherwise, both directions of
arity mismatch, and each of those again behind route-level middleware — and asserts the full
argument list, not the status code, for every one of them, twice: with an empty chain and with
a resolver registered that claims nothing.

**No handler shape changes when nothing is claimed, and that is now measured rather than
asserted.** An earlier revision of this ADR claimed one shape changed — a handler declaring a
required parameter neither the route, nor a default, nor any resolver can fill — and argued
that "nothing well-formed reaches this path". Both halves were wrong. `show(string $missing,
string $present = 'x')` on `/show/{present}` is well-formed PHP with no deprecation attached
to it, and it is one of eleven shapes a differential run found returning 500 where the
previous kernel returned 200:

| shape                                                    | route         | previous kernel        |
| -------------------------------------------------------- | ------------- | ---------------------- |
| `f(string $missing, string $present = 'd')`              | `/{present}`  | `f('pv')` → 200        |
| `f(Request $r, string $missing, string $present = 'd')`  | `/{present}`  | `f($r, 'pv')` → 200    |
| `f(?string $missing, string $present = 'd')`             | `/{present}`  | `f('pv')` → 200        |
| `f($missing, string $present = 'd')`                     | `/{present}`  | `f('pv')` → 200        |
| `f(A\|string $missing, string $present = 'd')`           | `/{present}`  | `f('pv')` → 200        |
| `f(mixed $missing, string $present = 'd')`               | `/{present}`  | `f('pv')` → 200        |
| `f(string $missing, string ...$rest)`                    | `/{rest}`     | `f('rv')` → 200        |
| `f(string $missing, string $b, string $c = 'd')`         | `/{b}/{c}`    | `f('bv', 'cv')` → 200  |
| `f(string $m1, string $m2, string $p1 = 'a', $p2 = 'b')` | `/{p1}/{p2}`  | `f('x', 'y')` → 200    |
| `f(string $missing, string $present = 'd')`              | no parameters | `f('d')` → 200         |
| `__invoke(string $missing, string $present = 'd')`       | `/{present}`  | `__invoke('pv')` → 200 |

Every one of those calls delivers a value to a parameter it was not meant for, so every one
of them is a latent application bug — but it is the application's bug, in the application's
code, and a framework that converts it into a 500 during an RC breaks the application at the
worst possible moment for the smallest possible gain. The kernel keeps calling them exactly
as it did. What the resolver seam adds is the guarantee described in decision 1a: the moment
a value the framework itself produced under an authorization decision is in that list, the
call is made by name and the misdelivery is impossible.

`tests/Unit/Core/Controller/HandlerInvocationContractTest.php` pins the whole table. Every
expected argument list in it was captured by differential execution against the pre-change
kernel, and each shape is asserted twice — once with an empty chain, once with a resolver
that claims nothing — because "a registered resolver does not change how any handler is
called" is the property that actually protects applications.

**Composing a second resolver is what the seam is for, and it took a second attempt to
work.** The first version of `BoundModelArgumentResolver` stopped claiming at the first
parameter it could not fill from the route parameters, as a local defence against the
positional shift above. That made its output a function of another resolver's parameter
being declared before its own: with a service resolver registered, `show(Service $s, Post
$post, string $tab)` lost the claim on `$post`, the raw identifier from the URL was spread
into a parameter typed `Post`, and the route returned a hard 500 — on precisely the routes
route model binding exists to serve. Fixing the shift in the kernel removed both the defence
and the need for it. `tests/Unit/Core/Controller/ResolverCompositionTest.php` dispatches
three signature shapes through a real kernel with two resolvers registered, in both
registration orders.

**New public surface.** Six types under `#[Api(since: '1.0.0-rc.11')]`. One limitation is
frozen into it deliberately: `HandlerParameter::$type` is null for union and intersection
types, because neither can be verified against a single class name, so a resolver cannot
claim `A|B $x` on type evidence. Widening the value object after 1.0.0 would be breaking,
and this is an accepted limitation rather than an oversight.

**Precedence is a security property, so it belongs to the claim and not to the composition
root.** Two earlier revisions of this paragraph got it wrong in the same direction. The first
asserted that first-claim-wins protected the bound model; the second recorded, correctly, that
it did not — `ModelBindingWiring` registers `BoundModelArgumentResolver` through
`DeferredComposition` at the END of boot, so an application resolver added from a service
provider is registered FIRST and wins the name — and proposed a "register ahead of anything
already there" call as the repair.

That repair was rejected on further work, for the reason the defect itself demonstrates: any
ordering primitive leaves the guarantee to whoever wires the application last, and a
"register first" call is a race the moment two parties both want to be first. Decision 1b is
what shipped instead. A `SealedArgument` is decided by the claim, in both directions, so the
framework's resolver being permanently last on the chain costs it nothing and no integrator
can arrange an order that breaks it — or an order that is required to make it work.

Uncontested names are unaffected either way: order changes nothing for a parameter only one
resolver claims, which is nearly all of them. Ordinary contested claims keep first-claim-wins,
because two interchangeable values are a composition preference and not a security question.

The residual risk is a denial of service rather than a bypass: a resolver that seals a name
`BoundModelArgumentResolver` also seals makes that route throw instead of serving a
substituted model. That is the intended trade — a route that stops working is visible on its
first request, where an unauthorized object delivered under a correct-looking type hint is
not.

**Route middleware cannot observe bound models.** The post-routing pipeline runs inside
route middleware, so `_bound_models` does not exist yet when a route middleware runs. The
alternative position would run binding — and its 401 under a regulated preset — before the
`auth` alias had authenticated the request. The trade is deliberate and documented at the
class.

**Resolved models are on the request as attributes.** `_model_<param>` and `_bound_models`
carry entity objects. Any component that enumerates `getAttributes()` — a debug bar, an
error-report serializer, a structured logger — will now serialize them. In a banking or
healthcare deployment that is personal data reaching a log through a routing feature, and
no redaction rule covers those names today. It needs one.

**Binding metadata is decided once per route shape, by the same mechanism.** Every input
`BindingResolver::resolveForRoute()` reads is frozen when the route table is built — the
handler's type hints, the route path, the route name, the parameter names the path declares,
and the resolver's own explicit bindings and compiled map. Two requests for the same route
differ only in the parameter VALUES, which decide nothing there. So the resolver memoises
its answer exactly as the kernel memoises `HandlerDescriptor`: an instance-level map, keyed
by a stable identity string, never invalidated, holding plain data. It is deliberately the
same mechanism and not a second one — a boot rebuilds the resolver, so a worker that
re-boots gets a fresh map for the same reason it gets a fresh resolver chain.

The key is `Class::method` plus the route name, the route path and the parameter names the
match produced, and each of those four is load-bearing: the path is where containment and
the parent relation are read from, the name is what the compiled map is indexed by, and an
optional placeholder makes one path match with and without a level. Nothing a caller sends
reaches the key, so the map is bounded by the route table and no volume of traffic can grow
it. A refusal is memoised alongside the successes and rethrown, so the fail-closed path — the
one an attacker can aim at by hammering a misdeclared nested route — is not left as the only
path still reflecting per request.

Measured per `resolveForRoute()` call, Xdebug off, OPcache on, PHP 8.5.9. The first harness
sampled the memoised and unmemoised versions alternately in one process so that drift in
machine state hit both arms equally (medians of 25 samples of 20 000 calls); a second,
independent harness written for this reconciliation measured hit against miss on the landed
code (medians of 15 samples of 20 000 calls, three repeats).

| route                                                       | before  | memo hit   | memo miss    |
| ----------------------------------------------------------- | ------- | ---------- | ------------ |
| `/users/{user}`                                             | 7.4 µs  | 1.0–1.4 µs | 7.9–12.3 µs  |
| `/users/{user}/posts/{post}`                                | 11.8 µs | 1.1–1.6 µs | 13.0–20.9 µs |
| `/orgs/{org}/accounts/{account}/transactions/{transaction}` | 16.6 µs | 1.1–1.6 µs | 19.6–29.0 µs |

**The absolutes did not reproduce and the ratio did.** The two harnesses disagree on the miss
cost by up to 40%; they agree that a hit beats a miss 6–9× at one level, 10–14× at two and
15–18× at three. The `before` column is a single-harness figure for code that no longer
exists and cannot be re-measured — it is kept as the historical record, not as something to
plan against. Reproduce on your own hardware before budgeting.

The hit is **nearly** flat with depth, not flat: 1.0–1.4 µs at one level against 1.1–1.6 µs at
three, a rise of roughly 15–25%. A hit builds the shape key and reads an array, and the key
contains the path and the parameter names, so it grows with those; what it does not do is grow
with the number of parameters to reflect or segments to parse, which is what the old cost did.
The earlier claim that the hit is "flat at about 1 µs whatever the depth" overstated it, and
the earlier "0.5–3 µs" for the key build overstated that too — timed on its own the key stayed
under a microsecond at three levels.

That trade lands differently per runtime. A persistent worker (RoadRunner, FrankenPHP) misses
once per route shape per process and hits forever after; the three-level route was costing
more than the entire `middleware.pipeline_5` budget (10 µs, `tools/php/performance-budgets.json`)
on every request and now costs a sixth to a tenth of it. PHP-FPM serves one request per
process, so every call is a miss and the memo is a small regression there. FPM's answer is a
compiled map, and there still is not one.

**What this ADR does not fix.** `CompiledBindingMap` still has no producer: `pulsar optimize`
builds no binding map, and `compiled_mode` is read by nothing. A bound map is what removes
the reflection from the FIRST decision as well as the later ones, which is the only decision
an FPM process makes, so until something builds one the memo helps persistent runtimes and
not FPM. `ModelBindingWiring` used to tell operators to run `pulsar optimize` to get a
compiled map; that instruction described a command that does nothing of the kind and has been
corrected to say what a bound map buys and that nothing ships to build it. It is recorded as a
limitation in `docs/route-model-binding.md` rather than described as behaviour.

What it no longer leaves unfixed is the availability of the escape hatches, which used to
compound with that gap: explicit bindings did not survive a `pulsar optimize` boot, because a
cached route table causes the project route files — and therefore every `Router::model()`
call — to be skipped, and the two were the only channels a `BindingScope` had. See the
decision below.

Nor does it fix the resolver-precedence gap recorded above, or give the binding refusals
anywhere to be read. Both stay recorded as consequences rather than left for a reader to find.

**Six behaviour changes worth a release note, all from decision 5.** Making the documented
scoping guarantee real means the path is now read as an assertion the framework has to check,
and routes that previously read it as decoration change:

1. A nested route whose second parameter is not actually a child of the first —
   `/users/{user}/settings/{setting}` where `Setting` is global — resolves nothing and returns
   **404** where it previously resolved the model unscoped. It is now a scoped lookup through a
   relation that does not exist. The route either loses the nesting or declares
   `BindingScope::Root`.
2. A nested route where nothing binds the parent — `/users/{user}/posts/{post}` handled by
   `show(Post $post)` — returns **500** where it previously resolved the post unscoped. This
   is the bypass itself, and the route now has to bind `{user}` with `Router::model()` even
   though no handler parameter wants the object.
3. A route whose placeholders describe no containment — `/compare/{a}/{b}`,
   `/users/{user}/posts-{post}` — returns **500** where both parameters previously resolved on
   their own key. Adjacent placeholders and a placeholder sharing a segment are now refusals,
   not root bindings.
4. A route whose parent shares its segment with literal text — `/u{user}/posts/{post}`,
   `/@{user}/posts/{post}` — returns **500** (`unreadableParent`) where the post previously
   resolved on its own key. Whether such a segment addresses a resource that contains the
   child is not something the path says, and a question the path does not answer is not one to
   answer permissively.
5. A route with two placeholders in one segment — `/{user}-{post}`, `/{tenant}.{resource}`,
   `/{a}{b}` — returns **500** (`placeholderSharesSegment`) for every placeholder after the
   first, where all of them previously resolved on their own key. It is change 4 applied
   inside a segment instead of across segments, and it is where the rule had survived
   longest: the parser advanced its "what precedes this" state once per segment, so every
   placeholder in a segment read the state from in front of the whole segment, and at the top
   of a path that state is "nothing contains me". The first placeholder in a segment is
   unaffected — `/{user}-{other}` still resolves `{user}` on its own key. `BindingScope::Root`
   is the whole of the escape here; `BindingScope::Contained` is not one and cannot be made
   into one, because a contained binding resolves through a parent parameter and part of a
   segment is not a parameter.
6. A handler parameter hinted with a union — `show(Post|string $post)` — is now bound and
   scoped, where the parameter was previously skipped entirely and the handler received the raw
   URL string in a slot that had declared a `Post` acceptable. On a nested route that means a
   **404** for a child that is not the parent's, where nothing was checked before.

All six fail closed, which is the shape of the bypass they close. Failing closed is only half
an answer, though, and the other half is that the way out has to be reachable and the refusal
has to be readable. One of those is done and one is not, and a reader deciding whether a URL
is a security boundary needs both stated:

- **The refusals are never logged.** `ModelBindingMiddleware` catches `ModelBindingException`
  and converts it into a response without writing anything to the logger; the 500 body is the
  fixed string `Internal Server Error`. The messages raised by `undeclaredParent()` and
  `undeterminedRelation()` name the offending parameters and list the three fixes, and none of
  that reaches an operator. The middleware needs to log what it catches.
- **The escape hatches now exist under `pulsar optimize`, and the route cache is what carries
  them.** They did not, and that was the more dangerous of the two gaps despite failing
  closed. A cached route table makes the kernel skip the project route files — that is what
  caching a route table means — so no `Router::model()` call ran, and the cache stored no
  declarations to make up for it. Each loss was loud (a lost parent binding a 500, a lost
  `Contained` relation a 500, a lost `Root` a 404) and none of them widened a read. The
  problem was that a fail-closed scoping rule whose only escape hatch is unavailable in
  production is a rule an operator switches off rather than uses, so the guarantee decision 5
  makes would have been traded away by exactly the deployments that need it.

  `routes.cache.bin` now holds a `CachedRouteTable` — the routes AND the declarations
  registered alongside them — under one manifest signature and one invalidation key, so
  "routes from this warm, declarations from that one" is not a representable state. The write
  side is `pulsar optimize`, which reads both off the same router; the read side is
  `Kernel::boot()`, which applies both before it locks the router for strict cache mode. A
  payload this build cannot read — an older shape, or a declaration that contradicts itself —
  is discarded whole and the boot goes cold, where the route files run and declare for real.
  Serving the routes and dropping the declarations that qualify them would be the same defect
  in a quieter register, so it is not one of the outcomes.

  Two things this costs. `FrameworkCacheInterface::warm()` gained the parameter recorded under
  Status; and a declaration edited in a route file does not reach a cached deployment until
  `pulsar optimize` runs again, which is the rule that already governed the routes themselves.
