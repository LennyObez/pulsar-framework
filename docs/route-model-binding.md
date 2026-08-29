# Route Model Binding

Route model binding turns a route parameter into the domain object it names. A controller
that type-hints `show(User $user)` on the route `/users/{user}` receives a loaded `User` —
resolved from persistence, constrained to the current tenant, and approved by an
authorization hook — instead of the string `"1"`.

The feature is opt-in by installation, not by a configuration flag. It composes only when a
persistence extension has bound a `ModelResolverPort` **and** an authorization hook exists.
With neither, `config/model_binding.php` is still read and its settings published, no
middleware is piped, and the request path is the one the framework takes without it. Read
[Turning it on](#turning-it-on) before anything else.

Handler invocation itself is described by
[ADR-0044](adr/0044-handler-arguments-are-resolved-not-spread.md); the sections below
describe binding.

---

## Turning it on

Four things must be true. `pulsar debug:wiring` checks the first two against a booted
container: the `model-binding` contract lists `ModelResolverPort`, `GateInterface`,
`TenantContext` and `CompiledBindingMap` as optional bindings, and every one that is missing
is printed as a degraded feature with the fix for it. A hook named under
`authorization_hook` satisfies requirement 2 without a Gate, so that line can be ignored when
you configured one.

### 1. A `ModelResolverPort` binding

Core owns the port and must not know that any ORM exists, so the adapter is bound from the
persistence side. `pulsar/orm` binds one unconditionally in `OrmServiceProvider::register()`;
any other persistence layer can bind its own implementation of
`Pulsar\Routing\Binding\Contract\ModelResolverPort` (see
[Custom resolvers](#custom-resolvers)).

Without this binding nothing is composed at all: no middleware is piped and nothing joins the
kernel's argument-resolver chain. The gate that decides not to compose is a single
`Container::has()` call at the end of boot, and the request path is left exactly as it is
without the feature.

### 2. An `AuthorizationHookInterface` binding

Resolved models are approved one by one before the controller sees them. The hook is chosen
in this order:

| Order | Source                                                     | Notes                                                                                          |
| ----- | ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| 1     | An `AuthorizationHookInterface` already bound by the app   | Bind your own and it is used as-is.                                                            |
| 2     | `authorization_hook` in `config/model_binding.php`         | A class name; resolved from the container, so it may take constructor dependencies.            |
| 3     | `PolicyAuthorizationHook`, when a `GateInterface` is bound | `AuthWiring` binds a Gate when `config/security.php` carries an `auth` section.                |
| 4     | Nothing                                                    | **Route model binding does not compose.** Models are not resolved and controllers see strings. |

There is deliberately no permissive fallback hook. `AuthorizationMiddleware` in this
framework default-denies — a route guarded by `auth` that declares no permission is refused,
not allowed — and a binding hook that default-allowed one frame away would hand every
authenticated caller every model a route names while the route attributes still claimed the
route was authorized. Refusing to compose fails in the only direction that is safe.

### 3. Something that says what each route parameter is

A parameter is bound when its name matches a route parameter and something names the model
class behind it: a controller type hint, a `Router::model()` call, or a compiled map entry.
See [Implicit binding](#implicit-binding) for the exact reflection rule, and
[How the model reaches the controller](#how-the-model-reaches-the-controller) for the
handler shapes that never receive bound models.

On a nested path this is a requirement about **every** placeholder in front of the one you
care about, not only the one your controller wants. `show(Post $post)` on
`/users/{user}/posts/{post}` does not bind: nothing says what a `{user}` is, so the
containment the URL asserts cannot be checked, and the route refuses with a 500 rather than
resolving the post globally. [Scoped bindings](#scoped-bindings) is the section that
matters; read it before putting a bound parameter under another one.

### 4. (With `pulsar/orm`) mapped entities, and mapped relations for nested routes

The ORM adapter resolves through entity metadata: the key column must be mapped on the
entity, and a scoped route's relation must be a `#[Relation]` property on the parent whose
name equals the URL segment. See [Scoped bindings](#scoped-bindings).

---

## Architecture

### Key components

| Class                        | Namespace                         | Purpose                                                                                |
| ---------------------------- | --------------------------------- | -------------------------------------------------------------------------------------- |
| `ModelResolverPort`          | `Pulsar\Routing\Binding\Contract` | Port for resolving models; implemented by a persistence adapter                        |
| `AuthorizationHookInterface` | `Pulsar\Routing\Binding\Contract` | Approves each resolved model before the controller sees it                             |
| `ModelBinder`                | `Pulsar\Routing\Binding`          | Orchestrates the binding pipeline for one matched route                                |
| `BindingAuthorization`       | `Pulsar\Routing\Binding`          | The decision a route is bound under: a policy gate, or an exemption that proves itself |
| `BindingResolver`            | `Pulsar\Routing\Binding`          | Decides binding metadata from the path, reflection, or a map — once per route shape    |
| `ModelBindingMiddleware`     | `Pulsar\Routing\Binding`          | Resolves, authorizes, and attaches models to the request                               |
| `BoundModelArgumentResolver` | `Pulsar\Routing\Binding`          | Hands the attached models to the controller's parameters                               |
| `ModelBindingConfig`         | `Pulsar\Routing\Binding`          | Configuration DTO: preset, hook class, key allow-list                                  |
| `BindingPreset`              | `Pulsar\Routing\Binding`          | Closed set of enforcement levels; an invalid one cannot exist                          |
| `BindingScope`               | `Pulsar\Routing\Binding`          | Closed set of scopes; the only way to override what the path says about containment    |
| `ResolutionContext`          | `Pulsar\Routing\Binding`          | Per-request state passed to the resolver                                               |
| `BindingMeta`                | `Pulsar\Routing\Binding`          | Metadata describing a single parameter binding                                         |
| `PolicyAuthorizationHook`    | `Pulsar\Routing\Binding`          | Default hook; delegates to the Gate                                                    |
| `ModelBindingException`      | `Pulsar\Routing\Binding`          | Binding failure carrying an HTTP status code                                           |
| `PublicRoute`                | `Pulsar\Routing\Attribute`        | Permits the authorization opt-out under a regulated preset                             |
| `RouteAccess::Public`        | `Pulsar\Routing`                  | The same statement made at the registration site instead of on the handler             |
| `ModelBindingWiring`         | `Pulsar\Core\Wiring`              | Composition root for the whole feature                                                 |
| `CompiledBindingMap`         | `Pulsar\Routing\Binding`          | Pre-compiled metadata. **Nothing builds one** — see below                              |

### Request pipeline

```
Request
  │
  ├─ Global middleware pipeline                 authentication, CSRF, …
  │
  └─ Kernel::dispatchRoute()                    matches the route; the match travels as an
       │                                        argument, and is also attached as _route
       ├─ Route middleware                      the 'auth' alias and any per-route middleware
       │
       └─ PostRoutingPipeline                   innermost frame before the handler
            │                                   binds the dispatched route into the middleware
            ├─ ModelBindingMiddleware
            │    ├─ take the route              from the kernel, never from the _route attribute
            │    ├─ find the caller             from the request, never a container-held context
            │    ├─ plan the bindings           path + reflection (or a compiled map), memoised per route shape
            │    ├─ decide what needs no model  401 under a regulated preset, 403 for a refused opt-out
            │    ├─ coerce key values           ── nothing below this line runs for a refused caller
            │    ├─ per level, outside-in:      the parent before the child it scopes
            │    │    ├─ call ModelResolverPort  resolve() or resolveScoped()
            │    │    └─ authorize that model    404 on the first denial — no deeper level is read
            │    └─ attach _model_<param>, _bound_models
            │
            └─ Kernel::invokeHandler()
                 └─ argument-resolver chain     BoundModelArgumentResolver claims parameters,
                      └─ Controller             sealed against displacement
```

### The route cannot be substituted either

Everything the middleware decides is a property of the route: which parameters name a model,
which class each resolves to, whether the route declares the `_without_authorization` opt-out,
whether it is public, how a nested child is scoped to its parent. It takes that route from the
kernel, not from the `_route` request attribute.

The distinction is the whole of a bypass that used to be available. `_route` is an ordinary
request attribute: the kernel writes it once, and every frame between routing and here can
rewrite it — route middleware, anything piped into the post-routing pipeline, anything an
extension `prepend()`s in front of the binding middleware. The kernel never reads it back; it
invokes the handler of the route it matched. So a rewritten attribute could not change which
handler ran, only what the binding layer believed was running — and the binding layer's output
is a set of models the argument resolver treats as authorized. Naming a route that legally
opts out of authorization, while copying the real route's parameter names and values, produced
an unauthorized model sealed onto the real handler's entity-typed parameter.

There is nothing to compare now. `Kernel::dispatchRoute()` passes the dispatched `MatchedRoute`
to the pipeline, which binds a per-dispatch copy of every
`Pulsar\Http\Middleware\DispatchedRouteAwareInterface` middleware to it before the chain is
built — before any frame that could rewrite an attribute exists
([ADR-0051](adr/0051-the-dispatched-route-is-handed-down-not-read-off-the-request.md)).

If your own middleware decides anything from the route, implement that interface rather than
reading `_route`:

```php
use Pulsar\Http\Middleware\DispatchedRouteAwareInterface;
use Pulsar\Routing\MatchedRoute;

final class RouteScopedMiddleware implements DispatchedRouteAwareInterface
{
    private ?MatchedRoute $dispatchedRoute = null;

    public function forDispatchedRoute(MatchedRoute $route): self
    {
        // Return a COPY. One instance serves every request, and a persistent
        // worker may be serving several at once.
        $bound = clone $this;
        $bound->dispatchedRoute = $route;

        return $bound;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // null when nothing dispatched us — piped into the global pipeline, say.
        $route = $this->dispatchedRoute;
        // …
    }
}
```

The attribute is still there, and still correct for anything that only wants to observe the
route: the kernel restores its own value on the way into the post-routing pipeline and again
on the way into the handler frame, so a controller or an argument resolver reading `_route`
sees the route being served.

### The models cannot be substituted by another resolver

`BoundModelArgumentResolver` is one contributor to the kernel's argument-resolver chain, and
your application or an extension may register others. Every claim it makes is wrapped in a
`Pulsar\Core\Controller\SealedArgument`, which means an ordinary claim on the same parameter
name — from a resolver registered before it or after it — cannot take that name. The handler
receives the object the authorization hook approved, or the request fails; there is no third
outcome and no wiring order that changes it.

That is deliberately not a matter of where you register your own resolver. This wiring
composes at the very end of boot (see below), so `BoundModelArgumentResolver` is always the
LAST resolver on the chain; if precedence were registration order, every resolver you add
would outrank it.

If your own resolver produces a value that must not be displaced either — a subject taken
from a verified signature, a payload a policy engine already ruled on — wrap it in a
`SealedArgument` too. Two resolvers sealing the SAME parameter is refused rather than ranked:
the chain throws `ConflictingSealedArgumentException` on the first request that reaches the
route, naming the parameter, the second resolver and the handler.

### How it is composed, and what turns it off

`ModelBindingWiring` (in the composition root, `src/Core/Wiring/`) publishes
`ModelBindingConfig` from `config/model_binding.php` on every boot, and defers the rest of
the decision to the **end** of boot — after extensions have registered and after
`routes/web.php` and `routes/api.php` have loaded. That timing is load-bearing: the resolver
port is bound by an extension, and `Router::model()` is called from the route files, so
neither exists while the wiring loop is still running.

The middleware is piped into the kernel's **post-routing** pipeline, not the global one.
Global middleware runs before routing, where no route has been matched yet; the post-routing
pipeline is the innermost frame before the handler, which is the only position that is after
routing _and_ after every frame that can authenticate — the global `AuthenticationMiddleware`
and the route-level `auth` alias. It is also the pipeline the kernel hands the dispatched
route to, which is how the middleware gets it (see
[The route cannot be substituted either](#the-route-cannot-be-substituted-either)). The
trade-off is deliberate: route middleware runs **before** models are resolved and therefore
cannot read `_bound_models`.

Note what that position does and does not buy. It guarantees the middleware runs late enough
to know the caller; it does not guarantee anyone has worked out who the caller is, and it says
nothing about where the answer is read from. See
[Authorization](#authorization) for which frame authenticates and which only makes it
possible.

One consequence for `pulsar/orm` users: once `config/model_binding.php` exists, the
host-published `ModelBindingConfig` is what the ORM resolver enforces, in preference to a
`model_binding` section in the extension config. That is intentional — the middleware enforces
`preset` and the resolver enforces `allowed_key_names`, so a second copy of the section would
not be a duplicate but a different policy applied at the other half of the feature — but if
you previously configured `model_binding` as an extension section, move those values into
`config/model_binding.php`.

---

## Implicit binding

A controller parameter is bound implicitly when **both** of the following hold:

1. the route has a parameter of the same name;
2. the type hint names exactly one class that `class_exists()` resolves. An **interface** is
   never a candidate, and neither is a scalar builtin (`string`, `int`, `array`, …).

A union or an intersection is read the same way, on the classes in it:

| Type hint        | Binds   | Why                                                     |
| ---------------- | ------- | ------------------------------------------------------- |
| `Post`           | `Post`  | one class                                               |
| `?Post`          | `Post`  | one class                                               |
| `Post\|string`   | `Post`  | one class; `string` is not a candidate                  |
| `Post&Countable` | `Post`  | one class; `Countable` is an interface                  |
| `string\|int`    | nothing | no class, and no object can satisfy it — not a model    |
| `Post\|Comment`  | **500** | two classes, and nothing says which one the route binds |
| `object`         | **500** | an object belongs there, and nothing says which class   |
| `mixed`          | **500** | same                                                    |
| `object\|string` | **500** | same                                                    |
| `iterable`       | **500** | same                                                    |
| `?object`        | **500** | same                                                    |
| `callable`       | **500** | same                                                    |

#### Why a wide hint is refused rather than skipped

`object`, `mixed`, `iterable` and `callable` all accept an object and name no class, and
`ReflectionNamedType::isBuiltin()` reports every one of them as a builtin. Read as "not a
model", they made the route bind nothing — and a route that binds nothing is a route the
binding middleware hands straight on, so the regulated preset's identity requirement and the
authorization hook were skipped with it. `show(object $account)` served an anonymous caller a
`200` where `show(Account $account)` on the same route served a `401`: a type hint was an off
switch for authorization.

The refusal is a property of the **route**, not of the request. It is read off the handler
signature and the route path, decided once per route shape and replayed, so it is the same
answer for every caller and every id and can tell nobody whether a record exists. An anonymous
caller under a regulated preset still meets the ordinary `401`, decided before the diagnosis
is computed, so a misdeclared route and a well-formed one are indistinguishable to them.

Scalar hints are untouched: `/notes/{note}` with `show(string $note)` binds nothing, says so,
and reaches the handler with the raw segment exactly as it always has.

`Post|string $post` used to bind **nothing at all**, because the reflection filter required a
single named type and skipped everything else. On a nested route that meant no model was
resolved, no policy was consulted, no containment was checked, and the handler received the
raw URL string in a parameter whose type hint is what makes the route read as safe. Handing an
entity to a union that names it is the fix; refusing `Post|Comment` is the other half, because
picking the first class would make which model the route resolves — and therefore which policy
the authorization hook is asked about — depend on the order the union was written in. Name the
one you meant and the route works again:

```php
$router->model('post', Post::class);
```

Both refusals are raised after explicit bindings are applied, precisely so the advice they
give can be taken — `Router::model('account', Account::class)` makes `show(object $account)`
bind and authorize like any other bound parameter.

```php
// Route: /users/{user}
public function show(User $user): ResponseInterface
{
    // $user was resolved from the {user} route parameter before this method ran
    return Response::json($user);
}
```

The lookup key is the model's primary key. Implicit binding always emits the key name `id`,
which the ORM adapter reads as "the primary key, whatever the column is physically called" —
so an entity whose primary key column is `user_id` still binds. To look a model up by another
column, use the [`{param:key}`](#custom-keys) syntax.

Reflection answers one question only — **what** a parameter is. It has no say in **how** the
parameter is constrained; that is read off the route path, and only off the route path. So
`show(Post $post, User $user)` on `/users/{user}/posts/{post}` resolves the user first and
the post through the user, exactly as `show(User $user, Post $post)` does. Rewriting the
signature cannot move, weaken or remove an ownership check. See
[Scoped bindings](#scoped-bindings).

Only class-based handlers are reflected. A closure route is never model-bound (see
[How the model reaches the controller](#how-the-model-reaches-the-controller)).

---

## Explicit binding

`Router::model()` registers a parameter-to-model mapping that overrides implicit resolution:

```php
// routes/web.php
$router->model('user', User::class);

// With a custom resolver class for this parameter
$router->model('user', User::class, ApiUserResolver::class);
```

Use it when the route parameter name does not match a controller type hint, when the handler
does not type-hint the model at all, when one parameter needs a different resolver, or when a
nested route needs a parent bound that no controller parameter asks for.

An explicit binding replaces the model class and the resolver. By default it invents neither a
key nor a scope: the key name and the containment are still read off the route path
afterwards. `$router->model('post', Post::class)` on `/users/{user}/posts/{post}` is scoped to
the user — resolved through `User::$posts`, checked, 404 if the post is not the user's —
**provided something also binds `{user}`.** If nothing does, the route does not fall back to
an unscoped read; it refuses with a 500 naming both parameters. Binding a parent nobody asked
for is what `Router::model()` is for:

```php
// routes/web.php — /users/{user}/posts/{post} handled by show(Post $post)
$router->model('user', User::class);   // resolved solely to scope the post by it
```

### Declaring a scope

Two optional arguments state something the path cannot, and both are typed
(`Pulsar\Routing\Binding\BindingScope`) so neither can be reached by misspelling a string:

```php
use Pulsar\Routing\Binding\BindingScope;

// /users/{user}/blog-posts/{post} — no PHP property can be called `blog-posts`,
// so name the relation the child resolves through.
$router->model('post', Post::class, scope: BindingScope::Contained, parentRelation: 'posts');

// /users/{user}/settings/{setting} — Setting really is global. Say so, out loud.
$router->model('setting', Setting::class, scope: BindingScope::Root);
```

`Contained` still resolves the parent and still checks the child against it; it only replaces
the relation name the path would have supplied. `Root` turns the containment check off for
that parameter — nothing then verifies that the resource belongs to what precedes it in the
URL, so the handler or the authorization hook has to. Mind its reach: `Router::model()`
registers by parameter **name**, so `BindingScope::Root` on `post` makes every route in the
application that has a `{post}` in it resolve the post globally, nested or not.

An explicit binding that says nothing about scope (the default, `BindingScope::Path`) leaves a
compiled map's declaration alone, so registering one to swap a resolver cannot quietly discard
a relation the map recorded.

### Explicit bindings under `pulsar optimize`

They survive it. `pulsar optimize` reads the declarations off the router in the same breath as
the routes and writes both into `routes.cache.bin` as one payload, and a cached-route boot
loads both before it locks the router. A `BindingScope::Root`, a `Contained` relation and a
per-parameter resolver class all apply in an optimized deployment exactly as they do in
development, and nothing about how you write them changes.

That is worth stating as a fix rather than as a feature, because it was not always true. A
cached route table makes the kernel skip `routes/web.php` and `routes/api.php` — that is what
caching a route table means — so every `Router::model()` call was skipped with them, and the
route cache carried no declarations to make up for it. Each loss failed closed (a lost parent
binding was a 500, a lost `Contained` relation a 500, a lost `Root` a 404), so nothing read a
row it should not have. The problem was elsewhere: the only escape hatch out of a fail-closed
scoping rule existed in development and not in production, and an escape hatch that is missing
where it is needed is a rule that gets switched off instead of used.

Two consequences worth knowing:

- **Re-run `pulsar optimize` after editing a route file.** The declarations are compiled into
  the cache at that moment; editing `routes/web.php` afterwards changes nothing a cached boot
  reads. This is the same rule that has always applied to routes themselves — the cache's
  invalidation key covers `config/`, `composer.lock` and structural env, not `routes/`.
- **A cache this build cannot read is discarded whole, never half-applied.** A payload written
  by an older build, or one whose declarations contradict themselves, does not yield routes
  with the declarations dropped — the boot falls back to reading the route files, where they
  are made for real.

`CompiledBindingMap`, the other channel that could declare a scope, still has no producer
([Not built yet](#not-built-yet)). That gap is about avoiding reflection, not about
availability: `Router::model()` is a complete channel on its own, in every deployment.

---

## Custom keys

By default a model is resolved by its primary key. The `{param:key}` syntax in a route path
resolves it by another column:

```php
// Route: /users/{user:slug}
// Resolves User by the 'slug' column
public function show(User $user): ResponseInterface { /* … */ }
```

The key is part of the declaration, not of the URL. `/users/{user:slug}` matches
`/users/john-doe`, and the parameter is captured — and addressed everywhere downstream —
under its bare name:

```php
$request->getAttribute('_route')->parameter('user');   // 'john-doe'
$router->url('users.show', ['user' => 'john-doe']);    // '/users/john-doe'
$route->constraints;                                    // ['user' => '[a-z\-]+'] constrains {user:slug}
```

The syntax combines with the optional marker (`{page:slug?}`) and with
[scoped bindings](#scoped-bindings): on `/users/{user:slug}/posts/{post:uuid}` the user is
found by slug and the post by uuid, within that user's `posts`.

### Allowed key names

`allowed_key_names` in `ModelBindingConfig` bounds the set of columns a URL may look a model
up by. The default is `['id', 'uuid', 'slug']`.

```php
$config = ModelBindingConfig::fromArray([
    'allowed_key_names' => ['id', 'uuid', 'slug', 'code'],
]);
```

**The allow-list is enforced by the resolver, not by the middleware.** The ORM adapter checks
it before it builds any SQL, and rejects a key name that is not on the list, that is not a
valid identifier, that maps to no column on the entity, or that maps to an encrypted column —
each as a `ModelBindingException` the middleware turns into `400 Bad Request`. A custom
`ModelResolverPort` gets no enforcement for free: it is handed the key name and must apply
`ModelBindingConfig::$allowedKeyNames` itself.

Keep `id` on the list. Implicit binding emits that key name for every parameter, so removing
it takes every implicit binding in the application with it — each one becoming a
`400 Bad Request` rather than a 404.

Every name added to the list becomes a column an unauthenticated caller can probe for
existence by URL. Keep it short and keep it non-secret.

---

## Scoped bindings

**Containment in the route path is what makes a child scoped, and nothing else.**
`/users/{user}/posts/{post}` asserts that the post is one of that user's, so the framework
checks the assertion before the handler runs — by resolving the user and then resolving the
post through `User::$posts`.

```php
// Route: /users/{user}/posts/{post}
public function show(User $user, Post $post): ResponseInterface
{
    // $post belongs to $user, or this method was never reached
}
```

That comment holds, and it holds for two reasons that are worth separating. The check is not
conditional on the signature — `show(Post $post)` on the same route gets the same check, or
no post at all. And there is no branch on which the post resolves globally instead: the
outcomes are "checked and found", "checked and not found" (404) and "cannot be checked"
(500). It stops holding in exactly two cases, both of which someone has to write down:

- the parameter is declared `BindingScope::Root`, which is the documented way to ask for an
  unscoped child and reads as those words in review ([Explicit binding](#explicit-binding));
- the bound `ModelResolverPort` does not constrain by the parent. The framework calls
  `resolveScoped()` and trusts the answer; `pulsar/orm` fails closed, and a resolver you write
  must too ([Custom resolvers](#custom-resolvers), obligation 1).

Scoped resolution calls `ModelResolverPort::resolveScoped()` with the parent model and the
relation name:

```php
$resolver->resolveScoped(
    modelClass: Post::class,
    keyName: 'id',
    keyValue: '42',
    parent: $user,
    relation: 'posts',
    context: $context,
);
```

If the child does not exist within the parent relation, the request is a `404`.

The parent is authorized before that call is made. Under a preset that mandates
authorization, each level goes through the hook the moment it resolves, so a caller refused at
the parent is answered `404` without `resolveScoped()` ever running — see
[What a nested route lets a caller learn about the parent](#what-a-nested-route-lets-a-caller-learn-about-the-parent).

### How the parent and the relation are chosen

Both come from the route path, so you can predict them by reading the URL. A **resource
placeholder** is a placeholder that occupies a whole path segment and shares it with nothing —
`/{post}`, not `/posts-{post}`, not `/v{version}` and not `/{a}{b}`.

- **The parent** is the resource placeholder **immediately** in front, and never anything
  further back. A parameter is not scoped to a grandparent under any circumstances, including
  the case where the level in between is not bound to a model — a grandparent is not a value
  the path parser can produce, so there is no guard here to get wrong.
- **The relation** is the literal segment directly in front of the parameter, used verbatim.
  In `/users/{user}/posts/{post}` that is `posts`, so the post is resolved through
  `User::$posts`.
- **The controller signature contributes nothing.** It names what a parameter is. It has no
  input into whether the parameter is contained, what contains it, or whether the check runs.

### What makes a placeholder a resource

Occupying a whole segment is the entire test, and it is deliberately generous. It has to be:

```
/{locale}/posts/{post}     a locale, then every post
/{tenant}/posts/{post}     a tenant slug, then every post
/{owner}/posts/{post}      an owner, then that owner's posts
```

Those three are the same string. Nothing in a URL distinguishes an addressing prefix from a
parent resource, so the framework does not try. **Every whole-segment placeholder is a
candidate parent**, including a locale, a tenant slug and an API version written `{version}`.

The consequence is a refusal, not a silent global read. On `/{locale}/users/{user}` nothing is
bound to `{locale}`, so the containment the path asserts cannot be checked and the route 500s
naming `{locale}`. Reading it the other way — "nothing is bound to it, so ignore it" — is
exactly the rule that unscopes `/{owner}/posts/{post}`, and the two cannot be told apart.

State which one you meant, once:

```php
// {locale} is an addressing prefix; {user} is global despite sitting behind it.
$router->model('user', User::class, scope: BindingScope::Root);
```

Or move the prefix out of the way of the resources — `/users/{user}` with the locale in a
header, a subdomain or a query parameter — which needs no declaration at all.

### A segment that holds a placeholder without being one

`u{user}`, `@{user}`, `{user}-x` and `{a}{b}` each carry a placeholder without being one. The
same ambiguity applies and there is nowhere to put the answer, because such a segment is not a
resource placeholder and so cannot be a parent:

```
/u{user}/posts/{post}       the user's posts
/v{version}/posts/{post}    every post
```

So a segment of this shape **never lets containment pass through it**. A bound placeholder
anywhere after one in the path is refused (`500`) rather than resolved globally.
`/u{user}/posts/{post}` used to return any post in the table, whichever user the URL named,
because `u{user}` was never recorded as a preceding resource and nothing recorded that it had
been skipped either — the child arrived looking exactly like a placeholder at the top of a
path.

The answers are the same two lines as everywhere else: give the parent a segment of its own
(`/users/{user}/posts/{post}`), or declare the child `BindingScope::Root` if it really is
global. The **first** placeholder inside such a segment is unaffected as a **child** — on
`/u{user}` the `{user}` has nothing in front of it and resolves on its own key. A second one
in the same segment is a different matter, and the next section is about it.

### A segment two placeholders share

`/{user}-{post}`, `/{tenant}.{resource}` and `/{a}{b}` put two placeholders in one segment.
They read as containment to a person and are the same shape as `/{year}-{month}` to a parser,
which reads neither. So the rule above applies **inside** a segment as much as across
segments, reading left to right:

- The **first** placeholder in a segment is contained by whatever precedes the segment — the
  resource placeholder in front of it, or nothing at all at the top of a path, where it is a
  root. `/{user}-{other}` resolves `{user}` on its own key, exactly as `/u{user}` does.
- **Every placeholder after it** stands behind something the path does not name as a
  resource, and is refused: `500`, `placeholderSharesSegment`.

`/{user}-{post}` used to resolve both on their own key with no refusal anywhere, so post 20
came back under a URL naming user 1 — the containment bypass in the shape where the
containment is least legible. Every placeholder in a segment was handed the state from in
front of the whole segment, and at the top of a path that state is "nothing contains me".

Mind which escape hatch applies here. `BindingScope::Root` answers it;
`BindingScope::Contained` cannot, and not as an omission — a contained binding resolves
through a parent **parameter**, and part of a segment is not one:

```php
// `/{tenant}.{resource}` addresses a global resource behind a tenant label, and says so.
$router->model('resource', Resource::class, scope: BindingScope::Root);
```

The other answer is to give each resource a segment — `/{tenant}/resources/{resource}` —
which is what makes the containment checkable rather than merely readable.

**A declared parent is resolved whether or not any handler parameter wants it**, because the
framework needs the object to scope the child by. On `/users/{user}/posts/{post}` handled by
`show(Post $post)`, add `$router->model('user', User::class)` and the `User` is loaded and then
discarded — nothing is ever handed it — for no purpose other than being the thing the post is
checked against.

Note the precondition, because it is the difference between that route working and that route
being a 500. "Wants it" and "declares it" are separate questions, and only the second is
required. `show(Post $post)` reflects to a declaration for `{post}` and none for `{user}`, so
on its own the route refuses (`undeclaredParent`) rather than resolving the post globally —
that refusal is the last row of the second table below. Declaring the parent is what the
`Router::model()` call above is for, and once something declares it — a second controller
parameter, that call, or a compiled map entry — the handler's indifference costs nothing but
the read.

### Every outcome a nested placeholder has, and the one it does not

| The path says                                                          | Outcome                                                                       |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Nothing at all in front of this placeholder                            | Resolved globally on its own key                                              |
| A resource placeholder in front, bound, with a literal segment between | Resolved through the parent's relation; **404** if it is not in that relation |
| A resource placeholder in front, but nothing says what it is           | **500** — the child is not resolved (`undeclaredParent`)                      |
| A resource placeholder in front, but no segment names a relation       | **500** — the child is not resolved (`undeterminedRelation`)                  |
| A segment in front that holds a placeholder without being one          | **500** — the child is not resolved (`unreadableParent`)                      |
| Another placeholder in front of this one, inside the same segment      | **500** — the child is not resolved (`placeholderSharesSegment`)              |

There is no further row in which a nested child resolves globally because the framework could
not work out how to check it. Six shapes used to land in exactly that hole and now refuse:

| Route                                                        | Before                               | Now                                                                          |
| ------------------------------------------------------------ | ------------------------------------ | ---------------------------------------------------------------------------- |
| `/compare/{left}/{right}`                                    | both resolved on their own key       | `{right}` names no relation → 500                                            |
| `/users/{user}/posts-{post}`                                 | both resolved on their own key       | `{post}` is not a resource placeholder and names no relation → 500           |
| `/users/{user}/posts/{post}` with `show(Post $post)`         | post resolved globally — the bypass  | nothing says what `{user}` is → 500                                          |
| `/u{user}/posts/{post}`                                      | post resolved globally — the bypass  | `u{user}` cannot be read as a parent → 500                                   |
| `/{user}-{post}`                                             | both resolved on their own key       | `{post}` stands behind `{user}` in one segment → 500                         |
| `/users/{user}/posts/{post}` with `show(Post\|string $post)` | post never bound, raw id handed over | the union binds `Post`, and the post is scoped → 404 if it is not the user's |

The refusals are 500s, not 404s, and deliberately so: each one reports a route the application
declared and cannot serve, not a resource a caller failed to find. They are raised before any
read reaches the resolver, and they are decided once per route shape and then memoised, so a
misdeclared nested route costs the same whether it is requested once or a million times
([Cost per request](#cost-per-request)).

The way out of a refusal is to state what the path could not — bind the parent, name the
relation with `BindingScope::Contained`, or declare the parameter `BindingScope::Root` — all
three through `Router::model()`, and all three
[carried into an optimized deployment](#explicit-bindings-under-pulsar-optimize) with the
route table they qualify. The exception messages name the parameters and spell out the three
options.

A segment that does not name a relation on the parent resolves **nothing** — a 404. Resolvers
fail closed on an unknown relation rather than falling back to a global lookup, which is what
stops `/users/1/posts/20` from returning a post owned by user 2.

### What the ORM adapter requires of a scoped route

With `pulsar/orm` as the resolver, `resolveScoped()` resolves the child only when:

- the parent entity declares a `#[Relation]` property whose **name is exactly the URL
  segment** — `posts`, case-sensitive, no separator translation — and whose `target` is the
  child entity class. A mismatch on either resolves nothing;
- the relation is a `HasOne`, `HasMany` or `BelongsToMany`. `BelongsTo`, `HasManyThrough` and
  the `Morph*` types cannot be constrained from the child side with what the relation metadata
  carries, so they resolve nothing rather than resolving unscoped;
- the parent-side key the relation declares — `#[Relation(localKey: …)]`, defaulting to the
  primary key — maps to a readable, non-encrypted column holding a string or int.

Each of those failures is a 404, never a silent unscoped read.

A collection segment that no PHP property can be named after —
`/users/{user}/blog-posts/{post}` — therefore 404s, because `blog-posts` is passed through
verbatim and no `#[Relation]` can carry that name. Name the relation instead:

```php
$router->model('post', Post::class, scope: BindingScope::Contained, parentRelation: 'posts');
```

That is a real escape hatch and it works, in the direction the path could not express, and it
[survives `pulsar optimize`](#explicit-bindings-under-pulsar-optimize) — so a route table you
intend to cache needs no second shape.

> **Behaviour change.** A nested route whose second parameter is not actually a child of the
> first — `/users/{user}/settings/{setting}` where `Setting` is global — returns 404 where it
> previously resolved the model unscoped. It is now a scoped lookup through a `settings`
> relation that does not exist. Either give the route a path that does not read as containment
> (`/settings/{setting}`), or declare the parameter global on purpose with
> `$router->model('setting', Setting::class, scope: BindingScope::Root)` — bearing in mind
> that the declaration is by parameter name and so reaches every route with a `{setting}` in
> it. The declaration holds under `pulsar optimize`; re-run it after editing the route file.

---

## Soft-deleted models

`ResolutionContext::$includeTrashed` controls whether soft-deleted rows are resolvable. It is
`false` by default, and a resolver that supports soft deletes must consult it.

### Opting a route in

A route opts in with the `_with_trashed` route attribute, declared where the route is
registered:

```php
$router->add(new Route(
    methods: [Method::POST],
    path: '/admin/users/{user}/restore',
    handler: [UserAdminController::class, 'restore'],
    attributes: ['_with_trashed' => true],
));
```

`ModelBindingMiddleware` reads that attribute and nothing else. Route attributes are part of
the route table your application builds at boot — no header, query string, cookie or body
field can introduce one — so a caller cannot ask for a soft-deleted row on a route that does
not already grant it. A restore or archive endpoint sees its deleted rows while the read
endpoint next to it stays blind to them.

The opt-in only widens what the resolver may find. Every model it returns still passes through
the authorization hook, so the route grants no access it would not grant to a live row.

---

## Tenant-scoped resolution

When a `TenantContext` is bound and a tenant has been resolved for the current request, its ID
is passed to the resolver as `ResolutionContext::$tenantId`:

```php
public function resolve(
    string $modelClass,
    string $keyName,
    string|int $keyValue,
    ResolutionContext $context,
): ?object {
    $query = $this->queryBuilder->table($modelClass)->where($keyName, $keyValue);

    if ($context->tenantId !== null) {
        $query = $query->where('tenant_id', $context->tenantId);
    }

    return $query->first();
}
```

The ORM adapter goes further than the sketch above, and a custom resolver should too: for an
entity marked `#[TenantScoped]`, a context carrying **no** tenant resolves nothing rather than
resolving across tenants. It also queries rather than reading the identity map, so a resident
worker cannot hand back a row loaded under a different tenant.

---

## Authorization

After a model is resolved, `AuthorizationHookInterface::authorize()` decides whether the
authenticated identity may have it. The middleware runs the check per resolved model, in
binding order, and stops at the first denial.

The "identity" is the caller, and it counts only if it reports itself authenticated — an
anonymous identity counts as no identity.

#### The caller is not a request attribute

The caller comes from `AuthenticationState`, the holder the globally piped
`AuthenticationMiddleware` publishes the request's `SecurityContext` into. It does **not**
come from `_identity`, `identity` or `_security_context`.

Those are PSR-7 request attributes, and a PSR-7 attribute is writable by every frame in the
pipeline: an application middleware, an extension, a route middleware alias, anything
prepended to the post-routing pipeline. While the binding layer read them, authorization on
every bound route was decided by whichever frame wrote last — the same defect as trusting
`_route` to say which route is being served, one field over. They are still **written**,
because extension controllers read them, but they are an output of the auth stack now and
never an input to it.

The identity resolver the composition root hands the middleware takes **no argument**. There
is no request to read an attribute off, which is a property of the signature rather than a
convention someone has to keep.

Both auth arrangements are still covered, because the holder is what both leave behind:

| Frame                                        | What it publishes                                        | Does it authenticate? |
| -------------------------------------------- | -------------------------------------------------------- | --------------------- |
| `AuthenticationMiddleware` (piped globally)  | a lazy `SecurityContext`, into `AuthenticationState`     | **No**                |
| `AuthorizationMiddleware` (the `auth` alias) | resolves that same context and writes the caller through | Yes                   |

The global middleware is deliberately cheap: it builds a `SecurityContext` and defers the
guards until something calls `SecurityContext::identity()`. On a route that carries the `auth`
alias, that call has already happened and the answer is memoised. On a route that does not, it
has not happened at all — so the binding layer asks, once, through the same memo. An
`auth`-guarded bound route therefore authenticates once per request, and an unguarded one
authenticates exactly when a bound model needs a subject to be authorized for.

The holder is registered for per-request reset. On a resident worker it is cleared between
requests, because an identity left behind is the next caller's identity.

What this does **not** yet cover: the five route-level frames that gate access on their own —
`AuthorizationMiddleware`, `TwoFactorMiddleware`, `StepUpMiddleware`,
`SensitiveOperationMiddleware` and `LevelOfAssuranceMiddleware` — still take the caller from
the `_security_context` attribute. Today that is the same object the holder carries, because
`AuthenticationMiddleware` writes both from one construction, so the two channels agree. They
agree by construction and not by enforcement, and a frame that replaces the attribute
separates them: the binding layer keeps the framework's answer and those five take the
replacement. Nothing about model binding changes when they move to the holder; this is written
down so that "the binding layer is safe" is not read as "the request attribute is no longer an
authorization input anywhere".

With no `auth` section in `config/security.php` there is no auth stack, no holder and no
resolver: no caller is ever identified, a regulated preset refuses every bound route, and the
wiring contract reports `AuthenticationState` as a degraded feature rather than leaving the
401s to be discovered.

### Regulated presets

With `preset` set to `banking`, `healthcare` or `legal`, authorization is mandatory on every
bound model:

The first three of these are decided from route state alone, **before any model is read**, and
only the last needs one:

| Situation                                                       | Result                                                                      | Decided       |
| --------------------------------------------------------------- | --------------------------------------------------------------------------- | ------------- |
| Route sets `_without_authorization` and is not `#[PublicRoute]` | `403 Forbidden`, and an audit event is recorded                             | before a read |
| Route sets `_without_authorization` and is `#[PublicRoute]`     | The check is skipped for the route's models                                 | before a read |
| No authenticated identity                                       | `401 Unauthorized` before the resolver is called, and a counter incremented | before a read |
| Hook returns `false`                                            | `404 Not Found`, and an audit event is recorded                             | per level     |

Every denial that names somebody is recorded as `AuditEvent::Authorization` /
`AuditOutcome::Denied`, with `action` = `model_binding_authorization` and a `reason` in the
metadata — `unauthenticated`, `authorization_bypass_forbidden` or `policy_denied` — that tells
them apart. Two of those used to leave no record at all: they were moved in front of the
resolver to close the unauthenticated existence oracle, and the audit call stayed behind with
the hook.

### An identified caller is recorded in full; an anonymous one is counted

Which channel takes the record depends on whether the caller has a name, and the line is drawn
there rather than at severity because that is where the two halves become boundable on their
own terms.

A denial of an **identified** caller writes one audit-chain entry per occurrence, with
`resource` = the request path. No ceiling, no throttle, nothing in front of it: its volume is
bounded by the number of credentials that exist, and whoever floods it is named in every line
they add. A known actor refused a named resource is exactly the record an assessor asks for.

A denial decided with **no identity** writes nothing to the chain and nothing to disk. It
increments `pulsar_model_binding_anonymous_denials_total`, labelled by route and reason, and
that is all.

Not because the chain cannot hold an anonymous actor. It can — `AuditActor::anonymous()` is a
first-class constructor and `AuditActorKind::Anonymous` is a declared kind, so the framework's
position is that an unattributable request has a KIND of actor rather than none. Three other
things decide it:

- **Nothing was accessed.** The refusal is reached before any resolver runs — measured at zero
  resolver calls over 11,000 anonymous requests — so the entry would be evidence of an access
  that did not happen. These were records of traffic, and traffic is what the access log
  counts.
- **The key space would belong to the caller.** Under a regulated preset — the default — every
  bound route refuses an anonymous request, so an entry per request means one HMAC chain
  advance and one `LOCK_EX` append that an unauthenticated caller decides the volume of.
  Measured: 479 bytes and 916 µs against a 26 µs refusal, so the record costs 35× the request
  it records, and it is a process-wide serialization point on the sink's lock available to
  anyone who can open a socket.
- **Every record would be the same record.** Same actor, same action, same reason, same models,
  differing only in a path the caller chose. Ten thousand of them are not ten thousand facts.

A ceiling is not the answer to the second point, and this codebase tried one. A per-process
ledger took one entry per `(route, reason)` and refused everything past 64 distinct shapes —
so a caller who could reach 64 shapes silenced every anonymous denial after them. A ceiling
that can be filled is an audit-suppression switch whatever its capacity. It also bounded
nothing under PHP-FPM, where each request is its own process: measured at 300 chain entries
from 300 requests, against 1 from 300 in a persistent worker. It has been deleted rather than
resized.

What still records an anonymous denial:

- **The metric.** `pulsar_model_binding_anonymous_denials_total{route,reason}`, in memory,
  bounded by the route table, exported through the OpenMetrics endpoint. Both labels come from
  the route table for the same reason the old entry's `resource` did: a label taken from the
  request would rebuild the unbounded key space inside the process.
- **The application log**, every occurrence, with the concrete path, at `debug` — so an
  enumeration scan cannot fill a disk through it either.
- **The access log**, which is where the volume was always the right question for.

With `observability.metrics.enabled` off there is no registry, the refusal is unchanged, and
the count is the only thing lost. `ModelBindingWiring` declares `MetricRegistry` as an
optional binding so that shows up as a reported degraded feature rather than as a silence.

An audit write that fails does not become a failure to deny. The refusal is returned as it
would have been, and the failure is reported at `critical` — or to the server error log when
no logger is wired — so a chain that is silently recording nothing cannot look healthy. Every
identified denial is attempted, every failure is reported, and nothing is skipped on the
grounds that the last one failed.

### A route that cannot be served is diagnosed once, not once per request

A `500` out of the binding layer is not a fact about the request. `ambiguousBoundType`,
`undeclaredParent`, `undeterminedRelation`, `unreadableParent`, `placeholderSharesSegment`,
`containedWithoutParent` and `inconsistentScope` are read off declarations, are the same answer
for every caller and every id, and are memoised by `BindingResolver` and rethrown unchanged.

Logging them per request was the anonymous chain write again, one layer over: an `error` line
with a stack trace on every request to a route an unauthenticated caller can reach — outright
under a permissive preset, and under a regulated one on a `#[PublicRoute]` that declares
`_without_authorization`. Measured at 2,270 bytes and 743 µs per request over 2,000 requests,
with no ceiling.

So the diagnosis is written by the component that decides it, at `error`, the one time it is
decided. `handleBindingException()` then counts the request on
`pulsar_model_binding_refusals_total{route,status}` and drops the per-request line to `debug`
without the exception, since the trace is the same four frames every time and the message
repeats a diagnosis already published in full. Measured on the same 2,000 requests, at
production log levels: 1 line and 2,065 bytes total, and 20-41 µs per request against
743-756.

That is an aggregation and not a ceiling. There is no capacity and no claim: the
deduplication IS the resolver's memo, which must already hold an entry for the route shape
before a replay can happen at all, and no number of broken routes can stop the next one being
reported.

That split is not an optimization. Authentication does not need the model and authorization
does, so deciding them together meant loading the model to find out whether the caller was
allowed to ask for it. An anonymous request for an id that exists and one for an id that does
not would then differ in response timing and, on a scoped route, in error shape — an existence
oracle on every bound route, open to a caller with no credentials. Under a regulated preset
the 401 is now decided from the route and the caller, and an anonymous request reaches no
resolver whatever id it names.

### What an anonymous caller can learn, exactly

The refusal is built from the route and the request, so two requests that differ only in
whether the id names a real row are the same response: same status, same reason phrase, same
headers, same body — and the same work, because no resolver is called for either. Three
details of that are load-bearing and are asserted as such in
`tests/Unit/Routing/Binding/UnauthenticatedDisclosureTest.php`:

- **A route that binds nothing keeps its old behaviour.** The gate keys on what the route
  _binds_, never on whether it has parameters. `/notes/{note}` with a `string $note` handler
  parameter binds no model, has no authorization decision to make, and reaches the handler for
  an anonymous caller under the strictest preset. Keying on parameters instead would turn
  every string-parameter route in the application into a `401`.
- **Key coercion and scoping refusals sit behind the gate too.** A malformed `int` key
  (`400`) and a route whose containment cannot be checked (`500`) are decided from data the
  caller already has, but they are still route state: an anonymous caller under a regulated
  preset gets the same `401` for those as for anything else. An authenticated request still
  surfaces both.
- **The illegal opt-out is refused for what the route declares.** `_without_authorization` on
  a non-`#[PublicRoute]` route is a `403` whether or not the id exists, rather than a `403`
  for a real one and a `404` for an absent one. It is a property of the route table, identical
  for every caller and every id, so it is the one refusal that may name itself.
- **No refusal repeats what it was asked.** The body is the status' reason phrase and nothing
  else: not the model class, not the route segment the caller supplied. See
  [Error handling](#error-handling).

What ordering cannot close is closed by the answer instead. A policy answers "may this caller
have THIS record", which is unanswerable before the record is read, so the row IS read for an
authenticated caller and the hook decides after it. The refusal is then a `404` — the same
status, reason phrase, headers and body a record that does not exist produces — so a caller
the hook refuses learns nothing about which ids are real. It used to be a `403`, which made
every bound route an existence oracle for anyone with an account and no entitlement, and on a
nested route it disclosed the parent as well.

Nothing is lost from the diagnosis: the denial is recorded in the audit chain as
`policy_denied` with the actor, the model class and the parameter, and the exception message
naming the model and the key goes to the log. Both are written where an operator can read
them and the refused caller cannot.

The residue is timing. A refusal reads a row and a miss does not find one; no framework layer
can make a store take the same time for both.

### What a nested route lets a caller learn about the parent

A nested route resolves outside-in — `/records/{record}/entries/{entry}` fetches the record to
scope the entry — so the parent is read first and unconditionally. The hook therefore runs
**as each level resolves**, not over the finished map: `ModelBinder::bindWithMeta()` takes an
authorization gate and calls it on every model the moment it is resolved, before the next
lookup starts. A caller refused at the record never causes the entry to be looked up.

Authorizing afterwards, which is what this used to do, made every nested route an existence
oracle on its parent, and the hook was not even reached to make it one:

- the record was fetched, the entry was looked up inside it, and the request failed with a
  `404` **naming the entry** — an answer only a record that exists can produce;
- against a record that does not exist the request failed one step earlier, with a `404`
  naming the record, so the two cases were distinguishable without reading a status code;
- and both threw from inside the binder, before the authorization pass began, so the hook had
  run for neither level even though the parent had been read.

The property is asserted in `tests/Unit/Routing/Binding/NestedParentDisclosureTest.php` as an
interleaved log of resolver and hook calls, which is the only place the difference between
"authorized as it resolves" and "authorized afterwards" is visible.

What a refusal at the parent discloses is exactly what the parent's own route discloses —
which is nothing: `404` whether the record exists or not, the same answer described above.
Nesting adds no channel of its own.

### An unrecognized preset is refused, not defaulted

`preset` is typed: `BindingPreset` has exactly four cases, and
`ModelBindingConfig::fromArray()` converts the config string through
`BindingPreset::fromConfig()`, which throws a `ConfigException` for anything else. `Banking`,
`bankng`, `true` and `null` all fail the boot with a message naming the offending value and
the four valid ones.

This is not defensive decoration. The preset was a free-form string compared with a strict,
case-sensitive `in_array` against the regulated names, so a capital letter or a dropped
consonant missed the regulated branch and selected the permissive one in silence — the
security posture of an application turning on its own spelling, and failing open when the
spelling was wrong. Nothing reported it, because to the code that checked it an unknown
preset and a deliberately permissive one were the same value.

Note the shape of the fix: the type alone would not have closed it. Configuration arrives as
a string, so somewhere a string becomes a preset, and a conversion written
`tryFrom($value) ?? BindingPreset::Standard` would be the original defect one layer down.
`fromConfig()` is the only supported way in, and it refuses. There is no reading of this
setting that means "I do not know", because the two things it could fall back to differ in
whether a caller the framework cannot identify is handed the model.

### Standard preset

`config/model_binding.php` ships a regulated preset, and `ModelBindingConfig::DEFAULT_PRESET`
— the posture an application gets with no config file at all — is the same one. There used to
be a second route to `standard` that nobody reviews: the DTO's constructor default was the
permissive preset, so deleting a file selected the opposite policy from the one that file
documented. Saying nothing is not a decision, and it no longer picks the weaker of the two
enforcement levels. Reaching `standard` is now an edit somebody makes and a reviewer can see.
With it, authorization is opt-in:

| Situation                           | Result                                                    |
| ----------------------------------- | --------------------------------------------------------- |
| No authenticated identity           | Binding proceeds; a debug-level log line records the skip |
| Hook returns `false`                | `404 Not Found`, and an audit event is recorded           |
| Route sets `_without_authorization` | The check is skipped, `#[PublicRoute]` or not             |

### The authorization opt-out is a route attribute

There is no `withoutAuthorization()` router method. The opt-out is the `_without_authorization`
route attribute, set where the route is registered, exactly like `_with_trashed`:

```php
$router->add(new Route(
    methods: [Method::GET],
    path: '/products/{product}',
    handler: [ProductController::class, 'show'],
    attributes: ['_without_authorization' => true],
));
```

`#[PublicRoute]` does **not** exempt a route from authorization on its own — a route without
the attribute above is authorized normally whether or not its handler is marked public. The
attribute's only effect is to permit the opt-out under a regulated preset, where it also
records why:

```php
use Pulsar\Routing\Attribute\PublicRoute;

#[PublicRoute(reason: 'Public product listing')]
public function show(Product $product): ResponseInterface { /* … */ }
```

It may be applied to the method or to the controller class, and the lookup is cached per
handler for the process lifetime.

### Default authorization hook

`PolicyAuthorizationHook` asks the Gate for the permission the binding declares, defaulting to
`view`, with the resolved model as policy context:

```php
final readonly class PolicyAuthorizationHook implements AuthorizationHookInterface
{
    public function __construct(private GateInterface $gate) {}

    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool
    {
        $permission = $meta->authzPolicy ?? 'view';

        $context = new PolicyContext(
            permission: $permission,
            resource: $meta->class,
            attributes: ['model' => $model],
        );

        return $this->gate->allows($identity, $permission, $context);
    }
}
```

`BindingMeta::$authzPolicy` is carried through the pipeline, but nothing populates it today
except a `CompiledBindingMap` entry — and nothing builds one. In practice the default hook
always asks for `view`. A route that needs `update` or `delete` semantics must bind its own
`AuthorizationHookInterface` and derive the permission from the request.

### Audit logging

Denials are recorded through `AuditLoggerInterface` when one is bound:

| Field    | Value                         |
| -------- | ----------------------------- |
| Event    | `AuditEvent::Authorization`   |
| Outcome  | `AuditOutcome::Denied`        |
| Action   | `model_binding_authorization` |
| Resource | Request URI path              |
| Metadata | `['model' => $modelClass]`    |

A failure inside the audit logger never disrupts the request.

---

## How the model reaches the controller

The middleware resolves and authorizes; it does not deliver. Delivery is an argument resolver
on the kernel's chain ([ADR-0044](adr/0044-handler-arguments-are-resolved-not-spread.md)):
`BoundModelArgumentResolver` claims a handler parameter when a resolved model is registered
under that name **and** satisfies the parameter's declared class. A route parameter whose name
collides with a scalar parameter is left alone.

The chain takes more than one resolver, and binding composes with the others. The kernel asks
every registered resolver, merges the claims, and only then decides which parameters it can
fill — so a handler may mix bound models, values another resolver supplies, and raw route
parameters in any declaration order, and the result does not depend on which resolver was
registered first.

A parameter that nothing can fill is omitted from the argument list, exactly as it always
has been. What a bound model changes is delivery: once a resolved model is in the list and
some parameter ahead of a value was omitted, the kernel spreads the call as **named
arguments**, so the model reaches the parameter it was resolved for and the omitted
parameter raises an `ArgumentCountError` naming itself. A bound model is never slid into a
neighbouring slot, and a raw route string is never slid into the slot the model was
resolved for. On a handler where nothing was bound, the call is byte-identical to the one
the framework made before route model binding existed.

**A contested parameter name is decided by the claim, not by the order.** When two
resolvers claim the **same** parameter name, an ordinary claim is decided by registration
order — earlier wins — but every claim `BoundModelArgumentResolver` makes is wrapped in a
`Pulsar\Core\Controller\SealedArgument`, which no ordinary claim can displace from either
side. The authorized model reaches the handler whether your resolver was registered before it
or after it.

That is deliberately not something you have to arrange. `BoundModelArgumentResolver` is
registered at the very end of boot, after extensions and route files, because that is when the
resolver port and the explicit bindings exist — so a resolver registered from a wiring, a
service provider or a project bootstrap is **always** ahead of it. `ArgumentResolverRegistryInterface::add()`
appends and has no "register ahead of everything" call, and deliberately never grows one: an
ordering primitive would leave a security property to whoever wires the application last, and
is a race the moment two parties both want to be first.

If your own resolver produces a value that must not be displaced either, seal it the same way.
Two resolvers sealing the **same** parameter is refused rather than ranked — the chain throws
`ConflictingSealedArgumentException` and the request fails closed, on the first request that
reaches the route. Uncontested names are unaffected either way: order changes nothing for a
parameter only one resolver claims.

**A seal says where the value came from, so the request attribute alone does not produce one.**
`_bound_models` is how the middleware hands its work to the resolver, and it is also an ordinary
request attribute: any middleware inner to the binding one can write it. So the resolver seals a
model only when the framework's own `ModelBinder` recorded resolving **that instance**, for
**that parameter name**, from **that URL value**, **on this request** — and the binder records
only after the per-level authorization decision has permitted it. Putting your own object under
`_bound_models` therefore delivers nothing: the parameter is left unclaimed and falls through to
the route value or the declared default, exactly as it would on a route that binds nothing.
Deliver your own values by registering a resolver, which is the seam that exists for it.

"On this request" is the fourth of those four, and it is there because objects outlive requests.
Any persistence layer with an identity map hands the same instance to every request that asks
for that row, so an attestation keyed on instance, parameter and value alone would still be
true on a later request — a model one caller resolved and was authorized for, replayed onto a
handler serving somebody else. `ModelBindingMiddleware` opens a binding pass at the top of
every request it sees, before it looks at what the route binds, and an attestation attests only
for the pass that minted it. The pass is a counter held inside `BindingProvenance`: the request
object cannot carry it (PSR-7 clones on every `withAttribute()`, so the binder's request and
the resolver's are different instances) and a request attribute must not, since that is the
surface the seal exists not to trust.

**The binder is not a container service, and it takes no default authorization.**
`ModelBinder::bind()` and `bindWithMeta()` require a `BindingAuthorization`: either a policy
gate, or a named exemption that verifies its own declaration — `declaredWithoutAuthorization()`
refuses a route that does not carry the `_without_authorization` attribute, and
`unenforcedPreset()` refuses a regulated preset. Under a regulated preset, for a route that
declares no opt-out, the only decision that can be constructed is a gate, so "resolved but never
authorized" is not expressible rather than merely discouraged. The decision also names the route
it was made about, and binding a different route with it is refused.

**An attestation also names the dispatch it was minted for**, and that is the fifth thing it
says. Parameter and value are the two a forgery copies: `forDispatchedRoute()` is public because
`DispatchedRouteAwareInterface` requires it and `process()` is public because PSR-15 requires it,
so a frame inside the request can bind the binding middleware to a route of its own — one
declaring `_without_authorization` on a `#[PublicRoute]` handler, which is a legal exemption on
_that_ route — copy the real route's parameter name and value into the match, and mint a model no
policy was ever asked about. Every value-level check below then passes, because the forgery was
built to satisfy exactly those checks.

So an entry names the `MatchedRoute` it was minted under, and `attests()` answers only for the
route it is asked about, by object identity. The route the question arrives with is the kernel's
own: `Kernel::dispatchRoute()` restores its own `MatchedRoute` onto the request at the handler
frame, undoing anything the post-routing stack wrote, and `BoundModelArgumentResolver` reads it
there — the one frame where that attribute is not ordinary request surface. Router matching
allocates a fresh `MatchedRoute` per match, so "the same object" means "this dispatch".

Minting under a route of one's own choosing is still possible and buys nothing. Minting under the
kernel's own route buys exactly the binding that route already grants, decided by that route's
preset, opt-out and hook.

`ModelBindingWiring` hands the one binder it builds to the middleware as a private property and
does not register the binder. That removes the shortest mint — resolve the binder, call
`bindWithMeta()` — and it is worth removing, but it is not a boundary and is not what holds.
`Closure::bind()` reads a private property of any object that can be reached at all; the
middleware holding the binder **is** registered; and even unregistered it is handed back by
`PostRoutingPipeline::snapshot()`, which is public on a pipeline that is itself container-bound.
What holds is the paragraph above: a reachable mint is a useless one, because an entry is
credited only for the pass the kernel opened and the route the kernel is dispatching.

What that leaves standing is the caller. The identity the binding layer authorizes against still
arrives on the request, so a frame able to call the middleware can also hand it an identity of
its choosing — and the mint it makes under the kernel's own route is then a real decision about
the wrong subject. Closing that is a separate change in a separate place; the two together close
"the handler's declaration decides", and neither is sufficient alone.

**The chain itself cannot be swapped out.** `ArgumentResolverChain` is published in the container
so a wiring can `add()` to it and so tooling can read the registered list. Replacing that list
wholesale is a different power — a resolver that is no longer on the chain makes no claim, so
removing one removes its seal — and it belongs to the kernel's boot/shutdown cycle alone. The
kernel takes the single `ArgumentResolverLifecycle` handle in its constructor, before any wiring,
extension or route file runs; every later request for one is refused with
`ArgumentResolverLifecycleException`.

**`object` and `mixed` parameters receive bound models.** Both accept every object, so a handler
declaring `show(object $post)` or `show(mixed $post)` on a bound route gets the resolved entity
rather than the raw URL string. `iterable` and `callable` receive it only when the model is
`Traversable` or invokable, which is what PHP itself would accept. Every other builtin — `string`,
`int`, `array`, … — still takes the raw route value, so a scalar parameter that happens to
share a route parameter's name is untouched.

The models are also on the request:

```php
$user = $request->getAttribute('_model_user');    // one model by parameter name
$models = $request->getAttribute('_bound_models'); // ['user' => User, 'post' => Post, …]
```

Three handler shapes never receive bound models, and must read those attributes if they need
them:

| Shape                                              | Why                                                                                                      |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| A closure or other callable route handler          | It is not reflected; it receives `($request, $parameters)` as it always has, and nothing is bound at all |
| `handle(ServerRequestInterface $r, array $params)` | The legacy array-passing shape returns before the argument chain is consulted                            |
| Any route middleware                               | It runs outside the post-routing pipeline, before the models exist                                       |

Because those attributes hold entity objects, anything that serializes every request attribute
— a debug bar, an error-report serializer, a structured logger — will serialize domain data.
In a regulated deployment, review that before enabling binding.

---

## Key types and coercion

`BindingMeta::$keyType` decides whether the raw route value is coerced before it reaches the
resolver.

- Implicit bindings, explicit bindings and `{param:key}` bindings all declare `string`. The
  route value is passed through unchanged, and the resolver compares it against the mapped
  column. A `{param:key}` placeholder forces `string` even over a compiled entry that declared
  `int`, because a column named in the URL need not be numeric.
- A `CompiledBindingMap` entry may declare `int` — and gets it by **default**, since both
  `BindingMeta`'s constructor and the map's deserializer fall back to `int` when `key_type` is
  absent. The value is then strictly validated before any query runs: a value that is not a
  run of digits is a `404`, and so is a negative one. `"0"` is accepted. There is no loose
  casting, so `"abc"` never becomes `0` and never resolves record zero.

Since nothing builds a compiled map today, the `int` path is not reachable from a normal boot.
Note the two defaults point opposite ways — an omitted `key_type` in a map is `int`, an
omitted key everywhere else is `string` — so the first thing that builds a map must write
`key_type` explicitly rather than rely on either.

---

## Custom resolvers

Implement `ModelResolverPort` to resolve models from something other than the ORM — a read
model, a cache, an upstream API:

```php
use Override;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ResolutionContext;

final readonly class ApiUserResolver implements ModelResolverPort
{
    public function __construct(private UserApiClient $apiClient) {}

    #[Override]
    public function resolve(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        ResolutionContext $context,
    ): ?object {
        return $this->apiClient->findUser($keyName, $keyValue, $context->tenantId);
    }

    #[Override]
    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): ?object {
        return $this->apiClient->findChild($parent, $relation, $keyName, $keyValue);
    }
}
```

Four obligations, because the port is the only place they can be met:

1. **`resolveScoped()` must constrain by the parent.** Returning a model that is not in the
   parent's relation is the authorization bypass this feature exists to prevent. Fail closed on
   a relation you do not recognise — return `null`, never fall back to `resolve()`. This is the
   whole of the guarantee at the port: the framework decides _that_ a child is contained and
   hands you the parent object and the relation name (the literal path segment, verbatim), and
   then believes whatever you return. `$relation` is caller-influenced only in the sense that
   it comes from the route table, never from the request — but it is an arbitrary string as far
   as your code is concerned, so match it against a known set rather than using it to build a
   query.
2. **Enforce `ModelBindingConfig::$allowedKeyNames`** before the key name reaches a query, and
   map it to a column you control rather than interpolating it.
3. **Honour `$context`**: filter by `tenantId`, and exclude soft-deleted rows unless
   `includeTrashed` is set.
4. **Return `null` for a miss**, which becomes a 404. Throw only `ModelBindingException`; the
   middleware catches nothing else, so any other exception escapes binding entirely and is
   handled as an unexpected framework error.

Bind it as the port for the whole application:

```php
$container->bind(ModelResolverPort::class, ApiUserResolver::class);
```

or register it for a single route parameter through an explicit binding, in which case it is
resolved from the container and may take constructor dependencies:

```php
$router->model('user', User::class, ApiUserResolver::class);
```

Per-parameter resolvers ride on explicit bindings, so they travel wherever those do — the
class name is written into the route cache with the rest of the declaration and resolved from
the container on a cached boot exactly as on a cold one.

---

## Error handling

`ModelBindingException` carries an HTTP status code, and `ModelBindingMiddleware` maps it:

| Code on the exception | Response                    | Body                    |
| --------------------- | --------------------------- | ----------------------- |
| `404`                 | `404 Not Found`             | `Not Found`             |
| `403`                 | `403 Forbidden`             | `Forbidden`             |
| `400`                 | `400 Bad Request`           | `Bad Request`           |
| anything else         | `500 Internal Server Error` | `Internal Server Error` |

Responses are JSON when the request's `Accept` header contains `application/json`, plain text
otherwise.

**The body is the reason phrase and nothing else.** The `404` and `400` bodies used to carry
the exception message, and every factory on `ModelBindingException` names the model class
while three of them append the route parameter's raw value — so a miss answered
`No [App\Domain\Patient] found for [id] = "4181"`: the internal class layout of the
application, handed to an unauthenticated scanner, next to the segment the scanner had just
sent. There is now no parameter through which either could reach a body.

The message is not lost. `ModelBindingMiddleware` logs what it catches — `error` for the
`500`s, because each one is a route that has never worked for anyone, and `debug` for the
client errors, because a `404` is ordinary traffic and an id scan must not be able to fill a
disk through that line. The log context carries the status, the request path and the
exception itself.

Every refusal also carries its own headers, rather than leaving them to a pipeline that may
not be wired: `Content-Type` (the plain-text branch used to set none at all),
`Cache-Control: no-store` — a refusal that depends on who is asking must not be stored by a
shared cache keyed on the URL — `Vary: Accept`, since the body is negotiated from that header,
plus `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`
and a `default-src 'none'` CSP. They are the set `ProductionRenderer` gives an error page
built where no pipeline will decorate it, and they are a floor:
`SecurityHeadersMiddleware` wraps this frame from the outside and overwrites whatever it is
configured for.

The framework raises fifteen of the factory methods. Five of the eight 500s say the same thing
in different words — the route declares a containment the framework cannot check, so it
resolved nothing. The other three are not about containment: `ambiguousBoundType()` is a type
hint naming two models, `unbindableBoundType()` is one naming none while still admitting an
object, and `inconsistentScope()` is a malformed declaration rather than an unresolvable
route — in practice thrown while the route file or the compiled map is being
read, so it aborts the boot instead of reaching a response:

| Factory                           | Status | Raised by                                                                            |
| --------------------------------- | ------ | ------------------------------------------------------------------------------------ |
| `modelNotFound()`                 | 404    | `ModelBinder`, when the resolver returns `null`                                      |
| `invalidKeyType()`                | 404    | `ModelBinder`, when an `int` key receives a non-integer or negative value            |
| `unresolvedParent()`              | 404    | `ModelBinder`, when a scoped child's parent did not resolve                          |
| `authorizationFailed()`           | 404    | `ModelBinder`, when the authorization decision refuses a level                       |
| `invalidKeyName()`                | 400    | The ORM adapter, for a key name outside the allow-list or unmapped                   |
| `undeclaredParent()`              | 500    | `BindingResolver`, when nothing binds the placeholder in front of a nested one       |
| `undeterminedRelation()`          | 500    | `BindingResolver`, when no segment in front of a nested placeholder names a relation |
| `unreadableParent()`              | 500    | `BindingResolver`, when the segment in front holds a placeholder without being one   |
| `placeholderSharesSegment()`      | 500    | `BindingResolver`, for a placeholder behind another one in the same segment          |
| `containedWithoutParent()`        | 500    | `BindingResolver`, for a `Contained` declaration on a path with nothing in front     |
| `ambiguousBoundType()`            | 500    | `BindingResolver`, when a type hint names more than one bindable class               |
| `unbindableBoundType()`           | 500    | `BindingResolver`, when a type hint admits an object but names no bindable class     |
| `inconsistentScope()`             | 500    | `BindingMeta` / `ExplicitBinding`, for a scope its other fields contradict           |
| `undeclaredAuthorizationOptOut()` | 500    | `BindingAuthorization`, for an opt-out claimed on a route that declares none         |
| `authorizationMandatory()`        | 500    | `BindingAuthorization`, for an exemption claimed under a regulated preset            |
| `authorizationForAnotherRoute()`  | 500    | `ModelBinder`, when the decision was made about a different route                    |

`authorizationFailed()` is raised by `ModelBinder` when the authorization decision refuses a
level, which is how a denial stops the walk before the next level is read. It carries `404`,
not `403`, and that is deliberate: a status that differed from a missing row's would tell a
caller the policy refuses whether the id is real. The middleware records the denial itself, so
a hook does not have to, and the message reaches the log.
`authBypassForbidden()`, `missingPolicy()` and `missingResolver()` are part of the public
exception surface for custom resolvers and hooks to use — the framework does not raise them.
The `401` and the opt-out `403` are produced by the middleware as responses rather than as
exceptions.

The message of every one of these reaches the log — `error` for a 500, `debug` for a client
error — with the status, the path and the exception in the context. None of it reaches the
response body, which is the status' reason phrase; see
[Error handling](#error-handling).

Treat the three containment 500s as a route that has never worked rather than as an incident:
the decision that raised them is a property of the route table, identical for every request to
that route, and it is taken before any resolver is called.

---

## Configuration

`config/model_binding.php` is published on every boot; an absent file yields the DTO's own
defaults.

> **The shipped file and the DTO agree, and both are regulated.** `config/model_binding.php`
> ships `'preset' => 'banking'` and `ModelBindingConfig::DEFAULT_PRESET` is the same value, so
> deleting the file — or booting an application that never had one — keeps the regulated
> posture instead of quietly selecting the permissive one. The two used to disagree, and only
> the file was regulated: an absent config file is not an error, so the permissive preset was
> reachable by deleting something rather than by writing anything, and the refusal machinery
> under [An unrecognized preset is refused](#an-unrecognized-preset-is-refused-not-defaulted)
> cannot catch a preset that is not written down at all. Reaching `standard` is now an edit a
> reviewer can grep for, which is the only property that setting needs.

```php
readonly class ModelBindingConfig
{
    /** Regulated, and the same preset config/model_binding.php ships. */
    public const BindingPreset DEFAULT_PRESET = BindingPreset::Banking;

    public function __construct(
        public BindingPreset $preset = self::DEFAULT_PRESET,
        public ?string $authorizationHook = null,
        public array $allowedKeyNames = ['id', 'uuid', 'slug'],
        public bool $compiledMode = false,
    ) {}

    public static function fromArray(array $data): self;   // throws ConfigException on an unknown preset
    public function isRegulatedPreset(): bool;
}
```

| Option               | Type            | Default                  | Description                                                                                            |
| -------------------- | --------------- | ------------------------ | ------------------------------------------------------------------------------------------------------ |
| `preset`             | `BindingPreset` | `Banking` (regulated)    | Authorization preset: `standard`, `banking`, `healthcare`, `legal`. Any other value is refused at boot |
| `authorization_hook` | `class-string?` | `null`                   | Custom `AuthorizationHookInterface` implementation class                                               |
| `allowed_key_names`  | `list<string>`  | `['id', 'uuid', 'slug']` | Allow-list for key names in the `{param:key}` syntax                                                   |
| `compiled_mode`      | `bool`          | `false`                  | **Reserved; has no effect today** — see below                                                          |

```php
// config/model_binding.php
return [
    'preset' => 'banking',
    'authorization_hook' => CustomAuthHook::class,
    'allowed_key_names' => ['id', 'uuid', 'slug', 'code'],
];
```

---

## ResolutionContext

Passed to every resolver call:

| Property          | Type                   | Description                                                         |
| ----------------- | ---------------------- | ------------------------------------------------------------------- |
| `$tenantId`       | `?string`              | Current tenant, from `TenantContext` when one is bound and resolved |
| `$subjectId`      | `?string`              | Authenticated identity ID, from `AuthenticationState`               |
| `$includeTrashed` | `bool`                 | Set by the `_with_trashed` route attribute, and by nothing else     |
| `$attributes`     | `array<string, mixed>` | Reserved for custom resolvers; the middleware never populates it    |

---

## Cost per request

Binding metadata is decided once per **route shape**, not once per request. A route shape is
the handler (`Class::method`), the route path, the route name, and the set of parameter names
the match produced — everything the decision reads, and none of it can change between two
requests for the same route. The parameter _values_ differ and decide nothing.

`BindingResolver` therefore memoises its answer for the lifetime of the process, the same way
`Kernel` memoises the handler signatures it reflects. Refusals are memoised too: a route whose
containment cannot be checked is refused on the first request and on every one after it,
without re-reflecting.

Measured with Xdebug off and OPcache on, per call to `resolveForRoute()`, on PHP 8.5.9. Two
independent harnesses on the same machine, medians over 15–25 samples of 20 000 calls:

| Route                                                       | Memo miss    | Memo hit   | Miss ÷ hit |
| ----------------------------------------------------------- | ------------ | ---------- | ---------- |
| `/users/{user}`                                             | 7.9–12.3 µs  | 1.0–1.4 µs | 6–9×       |
| `/users/{user}/posts/{post}`                                | 13.0–20.9 µs | 1.1–1.6 µs | 10–14×     |
| `/orgs/{org}/accounts/{account}/transactions/{transaction}` | 19.6–29.0 µs | 1.1–1.6 µs | 15–18×     |

Read the ranges, not a midpoint. The two harnesses disagreed on the absolute miss cost by up
to 40% while agreeing on the ratio to within a couple of ×, so the ratio is the part that
reproduces and the absolute figures are this machine on those days. Reproduce them on yours
before budgeting against them.

A hit is **nearly** flat with depth — 1.0–1.4 µs at one level against 1.1–1.6 µs at three, a
rise of roughly 15–25% across the three shapes measured. It is not literally flat, because a
hit builds the shape key and the key contains the path and the parameter names; what it does
not do is grow with the number of parameters to reflect. A miss does everything the resolver
has always done plus building that key. Timed on its own the key build stayed well under a
microsecond even at three levels, which is the whole of what memoisation added to the miss
path.

What that means per runtime:

- **Persistent worker** (RoadRunner, FrankenPHP): one miss per route shape per process, hits
  for every request after it. This is where the memo pays — a three-level route drops from
  20–29 µs of reflection and path parsing to 1.1–1.6 µs of key lookup.
- **PHP-FPM**: the process serves one request, so every call is a miss and the key costs it a
  little it did not pay before. It is a small regression there, not a win. Removing the
  reflection from the first decision needs a bound `CompiledBindingMap`, and nothing builds
  one — see [Not built yet](#not-built-yet).

A bound route takes **two** hits per request, not one. The middleware asks what the route
would bind before it decides whether the caller may ask at all, and the binder asks again when
it resolves — the same memoised answer, read twice, because the second read is what keeps the
plan the gate saw and the plan the binder iterates from ever being two different things. On
the figures above that is roughly 1.0–1.6 µs added per bound request on a warm process, and
one extra key build on PHP-FPM. It buys the property in
[What an anonymous caller can learn, exactly](#what-an-anonymous-caller-can-learn-exactly):
no query runs for a caller who is going to be refused.

Nothing a caller sends reaches the memo key — no parameter value, no header, no query string —
so the map is bounded by the route table and traffic cannot grow it.

---

## Not built yet

These are recorded so nobody plans around them. Each is a gap in the implementation, not a
design choice.

| Gap                                                      | Consequence                                                                                                                                                                                                                                                                                                                    |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| No producer for `CompiledBindingMap`                     | `pulsar optimize` builds no binding map, and nothing else does either. `BindingResolver` reflects the controller signature once per route shape per process and memoises the answer — see [Cost per request](#cost-per-request) for what that leaves each runtime paying.                                                      |
| `compiled_mode` is read by nothing                       | Setting it changes no behaviour. A compiled map is used when one is bound, regardless of the flag.                                                                                                                                                                                                                             |
| Nothing populates `BindingMeta::$authzPolicy`            | The default hook always asks the Gate for `view`.                                                                                                                                                                                                                                                                              |
| No redaction rule for `_bound_models` / `_model_<param>` | Anything serializing all request attributes will serialize entity objects.                                                                                                                                                                                                                                                     |
| Two resolvers sealing one parameter refuse the request   | `BoundModelArgumentResolver` seals every claim, and a second seal on the same parameter throws rather than picking one. A custom resolver that seals a bound route's parameter name takes that route down instead of substituting a model — see [How the model reaches the controller](#how-the-model-reaches-the-controller). |
