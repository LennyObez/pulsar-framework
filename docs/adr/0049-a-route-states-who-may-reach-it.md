# ADR-0049: A route states who may reach it

## Status

Accepted. Gives every route the framework registers an explicit access declaration,
adds a boot-time check that refuses an undeclared one, hardens the RUM collection
endpoint, and stops the health probe publishing infrastructure detail to anonymous
callers. Adds three `#[Api]` classes (`Pulsar\Routing\RouteAccess`,
`Pulsar\Routing\RouteAccessRegistrar`, `Pulsar\Observability\Rum\RumUrlLabels`), one
`#[Internal]` class (`Pulsar\Routing\RouteAccessReporter`), and two optional
constructor parameters. Continues
[ADR-0041](0041-the-token-vault-takes-a-connection.md) and
[ADR-0048](0048-a-guard-is-something-a-request-runs-into.md) into the routing layer.

## Context

Twenty-five `$router->` registrations under `src/` named no permission. Four of them
are worth stating plainly: `/health`, `/_pulsar/diagnostics`, `/_pulsar/rum/collect`,
and `/api/i18n/{locale}.json`.

### An omission is not a default

`AuthorizationMiddleware` default-denies a route whose `permissions` attribute is
empty. The comment above that branch is careful and correct — falling through would
let every authenticated user past with no authorization check at all — but it has a
consequence the registrations never accounted for. The middleware is a route-level
alias, not a global. A route that declares nothing is therefore:

- **open** in a deployment whose pipeline does not include it, and
- **closed to everyone** in a deployment that does.

Which of the two a given installation got was settled by middleware ordering. Nobody
chose it, nobody could see it, and the two outcomes are as far apart as two outcomes
get. A route's exposure must not be a property of a deployment's middleware list.

### The registrations that were wrong, and the ones that were only silent

Most of the twenty-five turned out to be genuinely public, and the work of deciding
was the point: the design-system assets a browser fetches while rendering the login
page, the anti-spam challenge scripts that exist to tell a human from a bot _before_
either has an account, the translation bundle the login form needs to render at all.
None of those can demand a credential without circularity. Saying so is what turns
them from twenty-five unanswered questions into twenty-five answers.

Three were not merely silent:

**`/broadcasting/auth` had an identity check nothing could reach.**
`BroadcastAuthController` refuses private and presence channels to an unauthenticated
caller by reading the `identity` request attribute. The only middleware that puts a
_resolved_ identity there is the `auth` alias, and the registration never asked for
it; the global `AuthenticationMiddleware` writes `AnonymousIdentity`. So the check saw
an anonymous identity on every request, and no caller — however logged in — could
subscribe to a private channel. A deny-by-default that denies everyone is a broken
feature, not a guarded one, and it is the exact shape ADR-0041 warns about.

**`/_pulsar/rum/collect` was an open beacon with a persistent effect.**
It accepts a JSON batch of web-vitals from a browser. There was no origin check, no
body cap, and — the part that outlives the request — the browser-supplied `url` field
became a metric label, truncated to 200 characters and otherwise unbounded. Truncation
limits how _long_ each label is and says nothing about how _many_ there can be. The
metric registry is an in-process array, so a caller sending `/a`, `/b`, `/c`… mints a
time series per request, for as long as it keeps sending: unbounded memory in the
worker and an attacker-authored metrics export for whatever scrapes it.

The shipped client made the same feature unreachable from the other end.
`resources/ui/js/rum.js` posted to `/api/rum/collect`; no router has ever served that
path. Every batch it sent was a 404.

**`/health` published the estate to anyone who could reach the probe.**
The status code is the load balancer's whole contract, and it must stay open. The
check _messages_ are a different thing: `812 MB free on /srv/app/var`,
`Database check failed: SQLSTATE[08006] could not connect to host db-01.internal`,
`Cache responded in 812.4ms`. Absolute paths, infrastructure hostnames, driver errors
and a timing side channel, returned in full to any anonymous request.

### The registration surface made silence the easy path

`$router->get($path, $handler, $name)` takes no attributes and no middleware. Stating
anything required dropping to `$router->add(new Route(...))` and eight lines. Two
bundled extensions had already discovered this and each grown its own private
`guarded()` helper. The framework's own wirings had not.

## Decision

**1. `RouteAccess` is the decision, recorded on the route.**

A four-case enum stored in the route's attributes alongside a one-line reason. The
cases name what _enforces_ the access, not merely who has it — a declaration that
names no enforcement is the same omission wearing a label:

| Case            | Enforced by                               | Used for                                                               |
| --------------- | ----------------------------------------- | ---------------------------------------------------------------------- |
| `Public`        | nothing, deliberately                     | assets, health probes, i18n bundles, anti-spam scripts, RUM collection |
| `Operator`      | `DiagnosticsAuthGuard` inside the handler | `/_pulsar/diagnostics`, the OpenMetrics exporter                       |
| `Signed`        | provider HMAC over the body               | the inbound mail webhook                                               |
| `Authenticated` | the `auth` alias plus a named permission  | `/broadcasting/auth`, the Swagger UI and spec                          |

`Operator` deliberately does not use the `auth` alias. The callers are scrapers and
on-call engineers, who carry a Bearer token and not a session, and the guard runs
inside the handler so the refusal does not depend on the pipeline.

**2. `RouteAccessRegistrar` makes the declaration one line, and fails closed.**

Each method takes the path, the handler, and the reason. `authenticated()` adds two
refusals that the old registration shape could not express:

- an empty permission list throws, because `AuthorizationMiddleware` reads it as
  deny-everyone and the resulting route is unreachable for every caller;
- when the `auth` alias is unregistered — `security.auth` unset, so `AuthWiring`
  returned before publishing it — the route is **not registered at all** and the
  omission is logged. A guarded route whose guard cannot run is worse than no route;
  404 is the honest answer.

**3. `RouteAccessReporter` runs at the end of boot, beside the collision reporter.**

It walks the assembled route table and reports framework routes that declare nothing:
warn in production, throw in debug. The handler class decides ownership — `Pulsar\`
minus the namespaces this repository reserves for code it does not ship
(`Pulsar\Extension\`, `Pulsar\Tests\`) — and the path prefix breaks the tie only for
closure handlers, which name no class. Reading the path first would have been wrong:
several extensions legitimately mount under the reserved `/_pulsar/` prefix.
Application routes are not judged; flagging them would bury the framework signal in
noise from every project, and a signal nobody reads is the state this started from.

The declaration survives `optimize --strict`: route attributes are already part of the
cached payload, and the cache's deserialization allowlist is derived from the
serialized bytes, including PHP's `E:` enum form.

**4. `RouteAccess::Public` and `#[PublicRoute]` are the same statement.**

`ModelBindingMiddleware` now honours either. The handler attribute states it on the
controller; the enum states it at the registration site, which is where a wiring that
owns the route but not the controller has to say it. The route attribute is read
before the per-handler cache, because that cache is keyed by `Class::method` and two
routes may share a handler.

**5. `/broadcasting/auth` is registered through the `auth` alias.**

With `_authenticated` rather than a named permission: which principals may join a
given channel is the application's `ChannelAuthorizer` decision, taken per channel
with the resolved identity. The route's own grant is "prove who you are first". This
also means public channels now require authentication through this endpoint — no loss,
since a public channel needs no token from it, and the only thing an anonymous caller
loses is the ability to probe it.

**6. The RUM endpoint is bounded on four axes, and the client points at it.**

Same-origin `Origin` header (browsers attach it to every POST, including
`navigator.sendBeacon`, so it costs a real client nothing and refuses a beacon
embedded in someone else's page); a 16 KiB body cap; the existing 100-entry batch cap;
and `RumUrlLabels`, which reduces the reported URL to its path, refuses anything that
could break an OpenMetrics exposition line, and admits at most 200 distinct values
before collapsing the rest onto `other`. A same-origin check is not authentication and
the code does not pretend otherwise — it is the control that matches the threat, which
is a page on someone else's site pointing at an anonymous endpoint. `rum.js` now posts
to `/_pulsar/rum/collect`.

**7. The health probe stays open; its detail does not.**

`HealthController` takes an optional authorizer. Callers without the operator token
get the overall status, every check's name, and every check's status — enough for an
orchestrator to act and for an operator to see which check is red — and no free-text
message and no latency. With no authorizer wired, nothing is in a position to call a
request privileged, and that is a refusal rather than a waiver.

**8. The Swagger UI and spec routes require `api.docs.read`.**

`swagger_ui_enabled` defaults to false, so turning it on is already an explicit act.
Publishing the full API contract of a banking or healthcare deployment to the internet
should be a second one.

**9. `MicroKernel` declares its routes `Public`, and its docblock stops lying.**

It has no authentication, no middleware registry and no `auth` alias, so every route
it registers is reachable by whatever its application's global middleware admits.
The class docblock promised "sensible security defaults (security headers, CSRF
protection, rate limiting)"; `boot()` pipes exactly what the caller passed to `use()`
and nothing else. A false claim about a control is worse than an absent control,
because it is the reason nobody looks.

## Consequences

- A new bare framework registration fails two gates: a source scan over `src/` in
  `FrameworkRouteAccessDeclarationTest`, and the boot-time reporter in debug. The scan
  carries its own proof that it fails — it is run against a fixture containing one.
- Deployments with `security.auth` unset lose `/broadcasting/auth` and the Swagger
  routes entirely, rather than serving them with unenforced permission lists.
- Applications using Swagger UI must grant `api.docs.read`; the seeded `admin` role
  holds `*` and therefore already has it.
- `/health` bodies shrink for unauthenticated callers. Status codes are unchanged, so
  no load balancer or orchestrator configuration is affected.
- RUM stops accepting cross-origin POSTs. The endpoint is registered in debug builds
  only, so no production deployment is affected either way.
- Scaffolding templates emit a comment pointing at `RouteAccessRegistrar`, so a
  generated module starts with the question asked rather than omitted.
- `show:routes` gains an `Access` column, so "which routes here can an anonymous
  request reach" is one command instead of a source read.

## What this does not close

`Router::resource()` and `apiResource()` still scaffold routes that carry middleware
and no permission, which means passing `['auth']` to them produces a set of routes
denied to every caller. They are an application-facing API and the decision is the
application's, so the shape is documented on both rather than changed; giving them a
permission argument is a separate, additive change.

Closure-handler routes are still dropped by the cache compiler rather than being an
error outside `--strict`, so a non-strict cached boot serves a table quietly missing
them; whether they belong in the cached table at all is a separate decision.

## The strict-cache boot, which this change broke and this change fixes

`Kernel::boot()` locks the router when it loads a strict route cache, and then runs the
wirings, which register. An earlier revision of this ADR called the resulting
`RoutingException::routerLocked()` a pre-existing defect in the cache path. It was not
pre-existing, and the record is corrected here rather than quietly dropped.

At the merge base (`2c9cd9e5d`, 1.0.0-rc.11) exactly two wirings registered a route at
boot — `DiagnosticsWiring` and `MetricsWiring` — each behind a config gate, and each
with a **closure** handler. `optimize --strict` refuses to write a cache while any
closure route exists, so on that revision a strict cache could only be produced by a
configuration under which neither route was registered. The lock was therefore never
reached: it had nothing to refuse.

This change is what made those routes reachable by the lock. Moving boot-time
registration onto `RouteAccessRegistrar` converted the handlers to class-based ones —
which is the point of the registrar, and correct — and class-based handlers are exactly
what `--strict` will cache. Eight wirings now register class-based routes at boot, and
`I18nWiring` is third in the boot order, so every deployment that ran
`optimize --strict` aborted its next boot. `AssetWiring` had been skipped under a strict
cache to get around the lock, which closed one of the eight and cost that wiring's
container bindings; skipping wirings one at a time was never going to close the rest.

The fix is in `Router::add()`, where the information is. The lock exists to keep a route
out of the served table that the verified cache does not contain, so the test is
identity rather than arrival: a registration equal in every field to a route already in
the cached table is the replay that wrote the cache and is dropped, and a registration
differing anywhere is drift between the deployed code and its cache and still refuses to
boot, now naming the path. Every wiring — `AssetWiring` included, its special case
removed — runs on a strict-cached boot exactly as it does on a cold one, and none of
them has to know a cache exists.
