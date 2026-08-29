# ADR-0023: Extension Trust Tiers & Capability Enforcement

## Status

Accepted

## The guarantee this document can make

Placed before the Context because everything after it is bounded by it, and because
this document is cited for SOX, HIPAA and PCI-DSS controls by readers who will not
reach page four.

An extension runs in the same PHP process as the framework.
`new ReflectionProperty(ScopedContainerProxy::class, 'inner')->getValue($scope)` returns
the real container, at any tier, in one line, and PHP offers no way to withhold private
state from code sharing the interpreter. Process isolation was considered and rejected
(see "Alternatives considered") for reasons that have not changed.

So the honest statement is this. **The sandbox stops accidental over-reach and casual
abuse by code that is not trying to escape. It does not stop a determined attacker who
already has code execution in the process.** Every control below should be read against
that sentence: they make reach explicit, auditable and deliberate, and they are worth
having for exactly that. They are not a memory boundary. An extension you install is code
you chose to run.

What follows from that, for a compliance reader: these are **preventive controls against
misconfiguration and unintentional over-privilege**, plus a record of what each extension
was granted. They are not a containment boundary for hostile code, and no statement in
this document should be cited as one.

**Where the record is.** Two host-owned files, both under change control, and nothing
else:

- `config/extensions.php` — the effective tier of every extension, and any per-extension
  capability the host granted on top of it. This is the file a reviewer diffs when a grant
  changes and an auditor reads to learn what third-party code was permitted.
- `CapabilityPolicy::defaults()` — the tier-to-capability table, reproduced below.

The extension's own `pulsar.json` is NOT part of the record. It carries a `trust_tier`
that is a request the host may only lower (ADR-0047), and it declares no capabilities at
all. This document used to open its Consequences with "extension authors must declare
required capabilities", which described a manifest field that has never existed; the
claim is gone rather than built, because a declaration written by the code being
constrained is a statement of intent and not a control — an extension that meant to
over-reach would declare whatever it needed.

What the two files say is what the runtime does. An entry naming a tier or a capability
the framework does not have is refused at load
(`TrustedExtensionsConfig::fromArray()`) rather than resolved down to Community and
dropped in silence, which is what it used to do: a typo in `additional_capabilities` read
in the file as a grant that had been made and behaved at runtime as a grant that had not.

## Context

Pulsar targets regulated domains - banking, healthcare, legal - where the supply-chain security of extensions is a first-class concern. The current extension system (ADR-0004) treats all extensions equally: once loaded via `pulsar.json`, every extension receives full `ContainerInterface` and `RouterInterface` access. This is a deliberate design choice for simplicity and third-party parity, but it creates unacceptable risk profiles:

- A compromised community extension can resolve `MasterKey`, raw database connections, and `AuditSinkInterface` from the container.
- Any extension can register routes at arbitrary paths, potentially shadowing `/login`, `/admin`, or `/_studio`.
- Middleware registration is unrestricted - a malicious extension could strip security headers or intercept authentication tokens.
- Extensions can bind services into the container, potentially overwriting critical framework bindings.
- Network egress, process execution, and environment variable access are unrestricted.

Compliance frameworks (SOX, HIPAA, PCI-DSS) require demonstrable controls over third-party code access to sensitive operations. An all-or-nothing model cannot satisfy these requirements.

## Decision drivers

1. **Compliance.** Regulated domains require auditable access controls over third-party code interacting with secrets, PII, and financial data.
2. **Defense-in-depth.** A single compromised extension should not cascade into full system compromise.
3. **Backward compatibility.** Existing first-party extensions and the bootstrap process must continue to work without modification when no policy is configured.
4. **Auditability.** Capability grants and denials must be deterministic, loggable, and explainable.
5. **Developer experience.** Error messages must tell extension authors exactly what capability is needed and how to request it.

## Decision

Introduce a four-tier trust model with capability-gated proxies for container and router access.

### Trust tiers

| Tier        | Intent                               | Resolution             |
| ----------- | ------------------------------------ | ---------------------- |
| `Core`      | First-party framework extensions     | Host policy allow-list |
| `Verified`  | Audited third-party extensions       | Host policy allow-list |
| `Community` | Unaudited third-party extensions     | Default for unknown    |
| `Untrusted` | Experimental or sandboxed extensions | Host policy allow-list |

### Requested vs. effective tier

Extensions declare a `trust_tier` in `pulsar.json` - this is **metadata only**, not a security boundary. The **effective tier** is resolved by the host application via `TrustedExtensionsConfig`:

```
pulsar.json: trust_tier = "verified"
Host config:  allowed_tier = "community"
───────────────────────────────────────────
Effective tier = min(requested, allowed) = "community"
```

If an extension is not in the host's allow-list, its effective tier defaults to `Community`. This ensures the host always controls the trust boundary.

### Capability model

Each tier grants a deterministic set of capabilities:

| Capability            | Core | Verified | Community | Untrusted |
| --------------------- | ---- | -------- | --------- | --------- |
| `ContainerRead`       | yes  | yes      | yes       | yes       |
| `ContainerWrite`      | yes  | no       | no        | no        |
| `ServiceRegister`     | yes  | yes      | yes       | no        |
| `ServiceDecorate`     | yes  | yes      | no        | no        |
| `RouteRegister`       | yes  | yes      | yes       | no        |
| `RouteRegisterGlobal` | yes  | yes      | no        | no        |
| `MiddlewareRegister`  | yes  | yes      | no        | no        |
| `CryptoKeyAccess`     | yes  | no       | no        | no        |
| `CryptoOperations`    | yes  | yes      | yes       | no        |
| `AuditWrite`          | yes  | yes      | yes       | no        |
| `AuditSinkAccess`     | yes  | yes      | no        | no        |
| `DatabaseRaw`         | yes  | yes      | no        | no        |
| `FilesystemWrite`     | yes  | yes      | no        | no        |
| `CommandRegister`     | yes  | yes      | yes       | no        |
| `NetworkEgress`       | yes  | yes      | no        | no        |
| `EnvRead`             | yes  | yes      | no        | no        |
| `ConfigWrite`         | yes  | yes      | no        | no        |
| `ProcessExec`         | yes  | no       | no        | no        |
| `AuthGuardAccess`     | yes  | yes      | no        | no        |

`AuthGuardAccess` is the one capability added since this table was first written, and it
was added because the model had no name for a power the framework plainly has: holding
`GuardInterface` — the `SessionGuard` behind it — lets code call `login()` with an identity
it constructed, `logout()` anyone, and rewrite the identity on the session in flight. That
service had no classification at all, so it was denied by default to every tier — including
`pulsar/forum`, which ships its own sign-in and registration pages and whose controllers
take the guard.

Safe-listing it would have handed `login()` to Untrusted, which holds `ContainerRead` and
nothing else — the same mistake as the safe-listed audit logger that let Untrusted write
audit entries without `AuditWrite`. Pricing it at some existing capability would have made
`config/extensions.php` record the wrong decision: an operator granting `ServiceDecorate`
is not granting authentication, and this file is the record an auditor reads. Naming the
power is the only answer that leaves the record true.

`ContainerWrite` — override or replace an EXISTING binding — is Core only. It was
originally granted down to Community, which is how an unaudited extension could rebind
`Session`, `Auth` or `CsrfGuard`. Registering a NEW binding is the lesser `ServiceRegister`
power that replaced it, and wrapping an existing service without discarding it is
`ServiceDecorate`.

Three of these — `MiddlewareRegister`, `CommandRegister` and `AuditWrite` — were declared
in `ExtensionCapability`, granted by `CapabilityPolicy`, printed in the table above, and
consulted by no code anywhere in `src/`. A capability that gates nothing is a claim, and
this table is cited for SOX, HIPAA and PCI-DSS controls. They now have enforcement sites:
the middleware pipeline and registry are priced at `MiddlewareRegister`,
`ExtensionBootstrap::buildCommand()` charges `CommandRegister` before it builds an
extension's CLI command, and the audit logger is priced at `AuditWrite`.

`ProcessExec` has no enforcement site and no service to price, because the framework
exposes no process-execution service — `ServeCommand` calls `proc_open()` directly. The
restriction map used to name `Pulsar\Process\ProcessManagerInterface`, a type that has
never existed here. The grant is inert either way (Core alone holds it, and Core bypasses
the map entirely); naming a fictional service made it look otherwise.

The host can grant additional per-extension capabilities via `TrustedExtensionsConfig`
(e.g., grant a specific community extension `DatabaseRaw`). A name that does not match a
capability in the table is a configuration error and refuses the boot; so is a `tier` that
is not one of the four. Both used to be swallowed — the capability was filtered out of the
list and the tier resolved to Community — which fails in the safe direction and is exactly
why it went unnoticed, because `config/extensions.php` is the only record of what an
extension was granted and it could say one thing while the runtime did another.

These grants reach the **container** proxy only. `ScopedRouterProxy` is constructed with the effective tier and the policy and is never handed the per-extension grants, so `RouteRegister` and `RouteRegisterGlobal` cannot be conferred this way — the router answers from the tier table above alone. The result is a denial, not an unintended grant, so the boundary holds; but a host that lists `RouteRegisterGlobal` under `additional_capabilities` gets no error and no effect. That is a different silence from the one above and the two should not be confused: `RouteRegisterGlobal` IS a capability name, so the config parses it and records the grant, and the router simply never reads it. Raising the tier is the supported route.

### Enforcement points

**Container access** — `ScopedContainerProxy` wraps `ContainerInterface`:

- `has()` answers for THE SCOPE: true when this container can return the entry, which is
  what PSR-11 asks of it. It used to report the host's binding table and was documented
  here as "never lies"; it was the lie. The scope cannot return `MasterKey` to a Verified
  extension — it throws — so the one idiom PSR-11 exists to support,
  `if ($c->has($x)) { $c->get($x); }`, became a trap. That is the guard every bundled
  extension uses to degrade around an optional service, and it is why `pulsar/cms` could
  not register at the tier this framework ships it at. Answering for the scope also stops
  `has()` being a free map of which crypto, database and audit services a deployment has
  wired, readable by a tier that may resolve none of them. It narrows the host's table and
  never widens it.
- `get()` checks the service against `ServiceRestrictionMap` and the extension's effective
  capabilities, then checks the VALUE that came back (see "Containment by construction").
- `bind()` / `singleton()` / `instance()` require `ServiceRegister` for an id nothing has
  bound, and `ContainerWrite` — Core only — to rebind one that is already bound. This
  document used to say both required `ContainerWrite`, which was wrong in the direction
  that flatters the control: it described Core-only power over an operation Community
  performs.
- `decorate()` requires `ServiceDecorate` AND asks the same question of the id that
  `get()` asks. A decorator is handed the service being decorated, so decorating is a
  resolution; it used to check the capability to decorate and never the service.
- `call()` resolves the callable's parameters through the proxy and invokes it directly.
- Core tier skips the proxy entirely (zero overhead).

**Service restriction** — `ServiceRestrictionMap` enforces deny-by-default:

- `restrictedServices`: maps service IDs to required capabilities (e.g. `MasterKey::class`
  requires `CryptoKeyAccess`).
- `safeServices`: explicit allowlist of services any tier with `ContainerRead` can resolve
  (loggers, config DTOs, event dispatcher, the template engine, the authorization gate).
- Unknown services (not in either list) are **denied** for non-Core tiers, with one
  exception that is not a loophole: an id the extension registered itself through its own
  scope, a type the extension SHIPS, and a type another loaded extension PUBLISHES. All
  three are facts about who built what rather than grants — see "An extension's own code"
  and "Another extension's published surface" below.
- **A host registry an extension contributes to costs `ServiceRegister`.** The framework
  owns four tables that exist so extensions can put something of their own into them and
  that also hand back what other extensions put there: the role registry, the event
  listener provider, the import/export registry, and (priced at `AuditWrite` instead,
  because appending to a record an operator reads after an incident is what that
  capability already means) the incident reporter. None belongs on the safe list, because
  a safe entry claims the id yields something inert and a registry holding every
  extension's contributions is not inert — `getProvider('users')` returns another
  extension's exporter, ready to invoke. `ServiceRegister` draws the line where it
  belongs: Untrusted holds `ContainerRead` alone and may read none of them; Community and
  above may contribute, and therefore may read.
- **The framework's own extension-integration surfaces are classified rather than left to
  deny-by-default.** The anti-spam pipeline and its AI-crawler settings are safe for the
  same reason the CSRF token manager is: `pulsar/cms` screens comments through the pipeline
  and `pulsar/forum` screens posts, so an extension denied it does not stop accepting user
  content — it accepts it unscreened. The business-profile provider is safe as
  configuration data. `ExtensionConfigRegistry` is safe and CONTAINED, narrowed on the way
  out to the sections the receiving extension itself ships. The password hasher costs
  `CryptoOperations`, which is what hashing and verifying a password is, and is granted
  down to Community so an extension shipping a registration form hashes properly rather
  than inventing something. The authentication guard costs `AuthGuardAccess`. The tenant
  context costs `DatabaseRaw`, because `set()` re-points every tenant-scoped query in the
  request — the same power as raw SQL by a shorter route, granted to the same tiers, and an
  extension already holding `DatabaseRaw` can read another tenant's rows by writing the
  query itself.
  All of these were found the same way and none of them at boot: they are constructor
  parameters of route handlers, and containment by construction checks those at the moment
  the first request arrives. See the field report below.

**Router access** — `ScopedRouterProxy` wraps `RouterInterface`:

- Community/Untrusted: routes are registered under `/ext/{extension-name}/...` (path) and
  `ext.{extension-name}.` (name). The name matters as much as the path: `Router::add()`
  writes `$namedRoutes[$name]` last-wins, so an extension registering a route called
  `login` re-pointed every URL generated for that name while its path sat dutifully inside
  `/ext/`. The prefix confined the path and not the name.
- Verified: no prefix constraint. A **catch-all** — a path that is one parameter segment
  and nothing else — costs `RouteRegisterGlobal`, the capability that exists for
  registrations a prefix cannot confine, and Verified holds it. This was spelled as a tier
  comparison (`tier === Verified` → refuse) that contradicted the capability table on the
  same page: the tier was refused the thing the refusal reported it as lacking. It cost
  `pulsar/cms` the catch-all a CMS is built around, so the extension this framework ships
  at `verified` could not boot at `verified` and stopped 166 routes short of its table.
  Community and Untrusted are not granted the capability and are still refused.
- A catch-all is admitted and is still not allowed to ANSWER a reserved path. The literal
  check below cannot see that case — the route names no reserved path and matches all five
  — so the refusal is compiled into the route: a negative lookahead is prepended to the
  catch-all parameter's constraint, and `Route` inlines constraints verbatim into the
  compiled pattern. `Router` already looks up static routes in an O(1) table before it
  scans any dynamic bucket, so a host route at `/login` wins regardless; the constraint is
  what covers the case where the host has no `/login` and the promise would otherwise hold
  only for the paths that did not need protecting.
- Core: full access, no proxy.
- **A route name the host's table already holds** cannot be taken by any extension this
  proxy covers. `Router` resolves a PATH collision first-registered-wins — the earlier
  route keeps the match tables and the later one is recorded as a collision for the boot
  reporter — and resolves a NAME collision by overwriting `$namedRoutes[$name]`. The name
  is what `route()` reads, so reserving `/login` stops an extension serving the sign-in
  page and does not stop it becoming the destination of every link, redirect and mail body
  the host generates to it. Community and Untrusted were incidentally covered by the
  `ext.{name}.` prefix; Verified was covered by nothing, on the stated ground that it
  "may register `/login` outright" — which the reserved-path list below had just made
  false. A registration whose path and handler match the route already holding the name is
  a route-cache replay and is allowed, exactly as `Router::add()` allows it for the path
  table.
- **Reserved paths** (`/login`, `/logout`, `/admin`, `/_studio`, `/api`) cannot be
  registered by any extension this proxy covers. This document claimed the control from
  the day it was written and there was no reserved-path list anywhere in the framework:
  Community and Untrusted were kept off `/login` by the `/ext/` prefix, which made the
  sentence accidentally true for them and simply false for Verified, which holds
  `RouteRegisterGlobal`. The list is EXACT paths, not prefixes — `/admin/tickets` is what
  `pulsar/tickets` exists to serve, and a prefix rule would refuse the extensions this
  framework ships in order to protect a page they were never going to shadow. The check
  runs on the path that will actually be registered, so a Community extension's own
  `/ext/acme-shop/login` is its own business.
- Every registering method funnels through `add()`. `resource()` and `apiResource()` were
  added by an earlier round of this work, asserted the capability, and forwarded the
  resource name to the inner router UNPREFIXED — seven routes at `/admin/...` named
  `admin.index` and the rest, from a Community extension, in the same change that reported
  route takeover closed. `SandboxSurfaceTest` enumerates the class by reflection and fails
  on a registering method that does not go through the one door.
- `group()` gives its callback a scoped router. `Router::group()` builds its sub-router
  with `new self()` and passes that to the callback, so the callback — extension code —
  held an unproxied `Router`.
- `model()` — the route-model-binding declaration — requires `RouteRegisterGlobal`, not merely
  `RouteRegister`. It registers by parameter **name**, so there is no prefix that could confine
  it: a `{post}` declared by an extension is the host's `{post}`, on every route that has one.
  What it can declare makes that reach a security decision — `BindingScope::Root` turns the
  containment check off, so a declaration from a community extension would unscope
  `/users/{user}/posts/{post}` application-wide, and `modelClass` picks which class the binding
  middleware resolves and the authorization hook is asked about. Core and Verified hold that
  capability; Community and Untrusted keep their prefixed routes and lose the ability to
  rewrite the host's binding rules. It was the one mutating method on the proxy that asserted
  nothing at all.

**Lifecycle** — `ExtensionBootstrap` scopes every phase, and there are five of them:
`register`, `preBoot`, `boot`, `postBoot` and `shutdown`. The fifth used to live in
`Kernel::shutdown()` and called `$extension->shutdown($container)` with the real container,
so an extension that could not obtain it in any of the first four was handed it on the way
out, at every tier, by the one phase nobody had counted. One scope is built per extension
and kept for the bootstrap's lifetime; four scopes for four phases meant a binding made in
`register()` was not recognised as the extension's own in `boot()`.

### Vetting names is a losing game

The enforcement above classifies by service **ID**, and the composition root decides what
an ID resolves to. `Pulsar\Container\ContainerInterface` sat on the `safeServices`
allowlist while `Kernel` bound it to the real container, so two calls —
`$scoped->get(ContainerInterface::class)` then `$real->get(anything)` — returned everything
the sandbox existed to withhold.

Removing that entry did not fix the class of bug, and neither did the round of filters that
followed it. That round added an allowlist of ids, a class-name check on anything bound by
name, and a four-hop reachability walk over a constructor. A reviewer then found ten more
escapes, all executable at Community tier, and **two of them were created by the fix**:

- `ScopedRouterProxy::resource()` and `::apiResource()` were added, asserted the capability,
  and forwarded the resource name unprefixed — re-opening route takeover in the change that
  reported it closed.
- The class-name check opened with `if (!class_exists($className)) { return; }`. A class
  that does not exist YET is not vetted, and an extension chooses when its own files load:
  bind, then declare, then resolve.

The rest of the list is the same shape. The four-hop walk was defeated by inserting one
more collaborator. The value guard was called from `get()` and covered one of its two
branches. A route handler was an unvetted class name the framework constructed with the
REAL container. `decorate()` never called the value guard at all. `FrameworkCacheInterface`
was safe-listed, so any tier with `ContainerRead` could rewrite the framework's boot
artifacts. The path prefix confined the path and not the route name.

Every one of those is a filter that was walked around by supplying the name later, one
level deeper, or through a door nobody had filtered. **A name is a promise about an object,
made by whoever wrote the name.**

### Containment by construction

So the model is inverted. Instead of predicting what a name will turn out to mean:

1. **Every value that leaves the scope is contained on the way out.** Not `get()` and not
   one of its branches — every exit: `get()`, `call()`, `decorate()`, provider
   instantiation, deferred provider registration, bound factory invocation, route handler
   construction, route middleware construction, CLI command construction, and the shutdown
   hook. A container or a router is exchanged for this extension's scoped equivalent, so
   code that legitimately holds one keeps working and gains nothing by holding it.
   Anything else that dispenses services is refused, unless the restriction map prices it
   and the extension holds the price — which is what lets `ConfigWrite` buy the config
   repository and `MiddlewareRegister` buy the middleware pipeline, instead of the guard
   being skipped for restricted ids the way it used to be.

2. **Everything the extension supplies is CONSTRUCTED THROUGH THE SCOPED PROXY**, never the
   real container. A class it binds by name, a closure it registers, a controller it routes
   to, a middleware it attaches, a command it declares: whatever builds the object receives
   the scoped proxy, so the object's own dependencies are resolved through the scope, and
   so are theirs, transitively, with no depth limit and nothing to know in advance. This is
   why the shape wins where filtering lost — it does not need to predict anything, and a
   class that does not exist yet cannot be built either.

   Route handlers, route middleware and CLI commands are constructed long after the
   extension that named them has finished booting, from whatever container the framework
   has to hand. The scope binds a factory for them at registration time, the same way
   `ScopedDeferredProvider` carries a scope across a deferred `register()`.

3. **A small closed set of ids never leaves the scope, contained or not** — the container,
   the bootstrap, the registry, the framework cache, the router — because containing them
   is meaningless.

The filters the inversion replaces are gone, not kept beside it. There is no constructor
reachability walk and no class-name check on a binding; two answers to one question is how
the depth limit and the class-name check came to disagree in the first place. What remains
of `SandboxReachAnalyzer` is a single CI check over the safe list's declared surface, which
is an early warning and not a runtime control.

### An extension's own configuration

`ExtensionConfigRegistry` is the object every bundled service provider reads its own
`config/<name>.php` out of, and it holds **every** extension's sections in one instance —
`payments` among them, whose section carries a webhook secret and a provider API key in any
real deployment.

It was in no category of the restriction map, so it fell to deny-by-default. That broke
`pulsar/booking` and `pulsar/payments` inside `boot()` and `pulsar/ai-governance` on the
first resolution of any of its contracts, and it is what a `core` grant in
`config/extensions.php` was written to route around — recorded there, wrongly, as
deny-by-default refusing an extension the ids it registers for itself. It does not: the two
rescues above answer that case, and had before the grant was written.

Classifying the registry is only half the repair, because a safe classification would hand
any tier holding `ContainerRead` — Untrusted included — every other extension's credentials
in one call. So it is the **third exchange**: the scope hands back a view narrowed to the
sections the receiving extension itself ships
(`ExtensionConfigRegistry::restrictedTo()`), exactly as it exchanges a container and a
router. The section names come from the extension's own `config/` directory, on the same
file-path reasoning as the rule above; the narrowing filters by section NAME, so an
operator's override of that extension's own section still arrives, and nothing else does.

An extension with no manifest path, or none shipping a config file, receives an empty
registry — which every consumer's `fromArray()` already reads as "not configured".

### An extension's own code

Deny-by-default is a rule about the HOST's service graph. An extension resolving its own
`Contracts\TicketRepositoryInterface`, or autowiring its own `Dsar\AnalyticsDsarCollector`,
is doing the thing extensions exist to do, and the previous round denied it — every bundled
extension above Core stopped working at the first resolution of its own binding.

Two facts rescue an unclassified id, both checked AFTER the restriction map so neither can
buy a restricted service:

- **The extension registered it through its own scope.** The registration already cost
  `ServiceRegister`, and the concrete was rebound to a scope-bound factory on the way in, so
  what comes back is something this scope built.
- **The extension SHIPS the type** — the class or interface is declared in a file inside the
  extension's own directory, as its manifest gives it.

The second is decided by file path, deliberately, and it is the one place identity is
asked about at all. A namespace prefix would be a name rule, and an extension can register
its own autoloader in `register()` and serve any name it likes from anywhere. Where a file
physically is, is not something an extension can restate: Composer owns the framework's
`src/`, so a framework type resolves outside every extension directory, and a type that
resolves inside one got there by being shipped there.

The same fact decides which classes a route registration may claim. `pulsar/analytics`
attaches the framework's own `CsrfMiddleware` to its routes; claiming it would have moved a
framework class's construction permanently inside one extension's tier, so the framework's
own use of it would answer to that extension's capabilities and could be made to fail by
it. Only code the extension ships is claimed.

An extension registered programmatically with no manifest path claims nothing, because
there is no directory to compare against. That is the one case where the mechanism has
nothing to work with, and it is pinned by a test rather than left to be discovered.

### Another extension's published surface

An extension reaching a PEER is neither host graph nor own code, and deny-by-default
refused it. That refusal broke every documented extension-to-extension integration this
framework ships: `pulsar/forum` registers its threads, posts and reports as back-office
resources through `pulsar/admin`'s `AdminGateway`, and its member pages as account
sections through `pulsar/cms`'s `AccountSectionRegistry`. Both calls are guarded by
`has()` so the consumer degrades when the provider is absent, and both were denied
outright.

Naming those types in the framework's restriction map is not the fix. The map describes
what a sandboxed extension may reach INTO THE HOST; the set of installed extensions is not
the host's to enumerate, changes with the deployment, and pointing a framework file at one
extension's contracts inverts the dependency ADR-0004 exists to keep pointing the other
way.

So the extensions answer for themselves. A manifest already declares `provides.services`,
and that list is now load-bearing: **a type is reachable from another extension's scope
when its declaring FILE sits inside some loaded extension's directory AND that extension's
manifest names it.** Everything else an extension ships — `Internal\`, controllers,
entities — stays private to it, which is what ADR-0002 and ADR-0009 already say about
module-private code.

The file decides and the name does not, for the reason given above: on its own a manifest
entry is a claim by whoever wrote it, so `acme/evil` could publish
`Pulsar\Security\Crypto\MasterKey` by typing the name. It cannot publish what it does not
ship — the type is attributed to the extension whose directory physically contains its
declaring file, and the manifest consulted is that extension's.

The CONSUMER is deliberately not asked to declare anything. Requiring the consumer's
manifest to name a `requires` or `suggests` dependency would look like mutual consent and
would be satisfied by the consumer adding a line to its own file — the same unauthenticated
self-claim as the manifest `trust_tier` this ADR already refuses to treat as a grant. It
would constrain the honest and nobody else. The decision belongs to the party whose service
is at stake, and only to it.

This grants reachability, not privilege. A peer's service still leaves the scope through
`contain()` like any other value, and the consuming extension's tier still governs
everything it does with what it got.

### Bootstrap integration

`ExtensionBootstrap` resolves effective tiers and builds one scope per extension, used by all five lifecycle phases. When no policy is configured (null), the container and router are passed unwrapped for full backward compatibility.

### Error model

`CapabilityDeniedException` provides deterministic, actionable error messages:

```
Extension "acme/analytics" (Community tier) cannot resolve service
"Pulsar\Security\Crypto\MasterKey" - requires CryptoKeyAccess capability.

To grant this capability, add to config/extensions.php:
  'acme/analytics' => ['tier' => 'verified']
or grant the specific capability:
  'acme/analytics' => ['additional_capabilities' => ['CryptoKeyAccess']]
```

## Alternatives considered

### Runtime sandboxing (process isolation)

PHP does not support lightweight process isolation. `pcntl_fork()` is unavailable on Windows and introduces IPC complexity. Fibers provide concurrency but not isolation. External sandboxing (Docker, chroot) is too heavyweight for per-extension isolation and breaks the single-process deployment model.

Rejected: impractical in PHP without unacceptable performance and complexity costs.

### Static analysis only

A Deptrac-style ruleset could flag imports of sensitive classes at analysis time. However, static analysis cannot prevent runtime service resolution via the container (`$container->get('some-service')`), which is the primary attack vector.

Rejected: insufficient for runtime secrets and dynamic service resolution.

### Per-service ACL without tiers

Grant/deny individual service access per extension without tier abstraction. This provides maximum granularity but creates an O(n×m) configuration matrix (extensions × services) that is impractical to manage and audit.

Rejected: poor DX, audit nightmare, does not scale.

## Consequences

### Positive

- **The host holds an auditable access-control record.** `config/extensions.php` states the
  effective tier of every extension and any capability granted on top of it, and
  `CapabilityPolicy::defaults()` states what a tier means. Both are checked-in files under
  change control, both are refused at load if they name a tier or capability the framework
  does not have, and `ExtensionSandboxDriftTest` fails if a bundled extension is missing
  from the list. Extension authors declare NOTHING that enters this record - see
  "The guarantee this document can make" for why that is deliberate.
- **Defense-in-depth against over-privilege.** A community extension cannot reach secrets, raw database connections or audit sinks THROUGH THE FRAMEWORK'S APIs — the container, the router, a constructor the framework fills, or a decorator. It is not a boundary against a compromised extension, which shares the process and can read private state by reflection; see "The guarantee this document can make".
- **Route integrity.** No extension this sandbox covers can register at a reserved path or
  take a route name the host's table already holds, and Community extensions are further
  confined to `/ext/{name}/` for the path and `ext.{name}.` for the route name. The name
  matters as much as the path, and differently: a path collides first-registered-wins in
  `Router` and a name collides last-wins, so the name is the half an extension could win.
  Community extensions also cannot register wildcard catch-alls.
- **Deny-by-default.** New sensitive services are automatically restricted until explicitly classified.
- **Backward compatible.** No policy configured = no proxy = existing behavior unchanged.
- **Deterministic.** Same extension + same policy = same effective capabilities. No runtime negotiation.

### Negative

- **Community extensions need explicit capability grants.** Non-trivial community extensions will need the host to grant specific capabilities in `config/extensions.php`.
- **New services must be classified.** Any new service added to the container should be placed in the restriction map or safe allowlist.
  Forgetting is not a loud failure everywhere: for a service an extension reaches through a
  `has()`-guarded call it now reads as "not configured", which is how `ExtensionConfigRegistry`
  went unclassified long enough for a Core grant to be written around it. That silence is
  the price of `has()` answering for the scope, and it is paid deliberately — the
  alternative was `has()` saying yes and `get()` throwing, which is not a quieter failure
  but a louder one in the wrong place. What compensates is
  `tests/Integration/Extensibility/BundledExtensionContractTest`, which boots the framework
  with its own extensions twice, once sandboxed and once with every extension at Core, and
  fails on any service or route the sandboxed boot delivers less of. An unclassified
  service that an extension actually needs shows up there as a difference, with the
  extension and the service named.
- **Increased conceptual surface.** Extension authors must understand the tier/capability model to debug access denials.

### Neutral

- **First-party extensions are NOT all unaffected, and that is the point.** This section
  used to say "all first-party extensions are tagged as Core in their manifests and the
  default host config", which stopped being true when the bundled products were moved to
  least privilege and was never corrected. `config/extensions.php` runs the framework's
  infrastructure and compliance extensions at Core and its **product** extensions at
  Verified - analytics, booking, cms, devices, feedback, forum, health-status, messaging,
  payments, releases, subscriptions, tickets and ai-governance at the time of writing;
  that file is the authority and `ExtensionSandboxDriftTest` keeps it complete. Those run
  THROUGH the proxies, so the sandbox is exercised by the framework's own products on
  every boot rather than only by third-party code that may never arrive. That boot is the
  regression test for every narrowing in this document, and it is how two earlier rounds
  of narrowing were caught breaking legitimate work — after they shipped, by reading, both
  times.
  It runs as a test now: `tests/Integration/Extensibility/BundledExtensionContractTest`
  boots the framework with its own `config/`, its own `extensions/` and every bundled
  product enabled, and requires every extension to reach `Booted`, to deliver every
  manifest-declared service and every route it delivers with the sandbox off, and to build
  every command it declares without a capability denial. The comparison is recomputed on
  each run rather than written down, so it cannot decay into an allowance.
- **Three bundled products hold `additional_capabilities`, and all three are the same
  finding.** `pulsar/cms`, `pulsar/payments` and `pulsar/analytics` derive keys from the
  master key — preview-link signing and the API-key pepper, the idempotency-record HMAC,
  and the rotating salt that pseudonymises a visitor — so they need `CryptoKeyAccess`,
  which Verified does not carry. Each is granted the capability in `config/extensions.php`
  with the reason written beside it, rather than raised to Core: the grant buys the one
  thing needed and leaves `ContainerWrite` and `ProcessExec` where they are. Two of the
  three would not have failed at boot — the payments binding is a lazy closure and the
  analytics one is behind a `has()` guard — so the first symptom would have been a 500 on
  the first idempotent payment and an analytics extension that silently tracked nothing.
- **Overhead is small and no longer all at boot.** A map lookup plus a capability check is
  O(1) and Core bypasses the proxy entirely, but containment by construction moved the
  construction of route handlers, route middleware and CLI commands into the scope, so a
  proxied extension pays those checks when the handler is first resolved rather than at
  boot. See "Performance impact".

## Field report

The framework booted with its own thirteen bundled products enabled, at the tiers
`config/extensions.php` grants them, and every finding below came out of that boot. None
came out of a unit test, and none could have: a unit test of the sandbox supplies its own
fixture extension, so it asserts the proxy does what the proxy was written to do. What was
wrong each time was the proxy's idea of what a real extension needs.

**Found at boot.** Seven host services no extension could reach because nothing classified
them: the configuration registry each extension reads its own `config/<name>.php` out of,
the import/export registry the kernel binds expressly for `postBoot()` registration, the
role registry, the event listener provider, the incident reporter, the anti-spam pipeline
and its AI-crawler settings. Two extension-to-extension integrations refused for want of
any rule about peers — `pulsar/forum` into `pulsar/admin`'s `AdminGateway` and into
`pulsar/cms`'s `AccountSectionRegistry`. And `pulsar/cms` unable to register the catch-all
a CMS is built around, because a tier comparison contradicted the capability table.

**Found only after boot, which is the part worth recording.** Containment by construction
moved route-handler, route-middleware and CLI-command construction into the extension's
scope, so a controller's constructor parameters are checked when the first request
arrives. Five bundled controllers were denied there — `pulsar/forum`'s sign-in,
registration and password-reset pages, `pulsar/cms`'s business-profile settings, and
`pulsar/payments`' checkout — while every boot-time check reported success. A lazily-bound
factory closure hides the same failure just as well: `pulsar/payments` binds its
idempotency-signing envelope as one, so the first symptom of its missing `CryptoKeyAccess`
would have been a 500 on the first idempotent payment, and `pulsar/analytics` guards its
key manager with `has()`, so the first symptom of ITS missing grant would have been an
analytics extension that silently tracked nothing.

Both classes are now executed by
`tests/Integration/Extensibility/BundledExtensionContractTest`, which boots the framework
with its own extensions, resolves every route-handler class and every id each extension
bound, and compares what a sandboxed boot delivers against the same boot with every
extension at Core.

## Security impact

Read against "The guarantee this document can make": these narrow what an extension reaches
**through the framework's own APIs**, which is where accidental over-reach and casual abuse
live. None of them survives reflection, and none should be cited as containment for hostile
code.

- **Secrets exfiltration:** `MasterKey`, `KeyProviderInterface` restricted to Core tier
  only — through `get()`, through `call()`, through a constructor the extension declares,
  and through `decorate()`, which are the four doors that used to disagree about it.
- **Database access:** Raw SQL connections restricted to Core + Verified tiers.
- **Audit:** the sink and the logger are priced separately, because they are different
  powers. Draining or reconfiguring the sink is `AuditSinkAccess` (Core + Verified);
  appending an entry is `AuditWrite` (down to Community, never Untrusted).
  `AuditLoggerInterface` used to be on the safe list while `AuditWrite` was enforced
  nowhere, so an Untrusted extension resolved the host's audit logger and wrote entries
  with it.
- **Boot artifacts:** `FrameworkCacheInterface` — the compiled route table, the container's
  resolution hints, the compiled views — is denied at every tier below Core. It was
  safe-listed, so any tier holding `ContainerRead` could rewrite what the next boot
  executes.
- **Route takeover:** an extension cannot register at a reserved path (`/login`, `/logout`,
  `/admin`, `/_studio`, `/api`) at any tier this sandbox covers, and cannot take a route
  NAME the host's table already holds. Community and Untrusted are further confined to
  `/ext/{name}/` for the path and `ext.{name}.` for the name. Both halves are needed and
  the second is the one that was missing: `Router` resolves a path collision
  first-registered-wins, so a host route registered before an extension keeps serving its
  path, and resolves a NAME collision last-wins, so an extension registering the name
  `login` re-pointed every `route('login')` in the host's templates, redirects and mail
  bodies while its own path sat dutifully elsewhere. Reserving the path and leaving the
  name available closes the door a visitor types and leaves open the one every link the
  application generates walks through.
- **Global middleware:** `MiddlewareRegister` gates the middleware pipeline and registry,
  which is what installs code in front of every request. Core and Verified hold it.
- **SSRF/exfiltration:** `NetworkEgress` gates the HTTP client, the mail manager, the
  WebSocket broadcast manager and the AI client for Community/Untrusted — everything the
  framework offers that originates outbound traffic.
- **Environment leakage:** `EnvRead` gates `Pulsar\Config\Environment`, the framework's
  environment reader. It does not and cannot prevent an extension from calling `getenv()`,
  or reading `$_ENV` and `$_SERVER`, which are PHP builtins available to any code in the
  process. This document used to say `EnvRead` "prevents community extensions from reading
  environment variables", which was never true of anything an in-process sandbox could do.

### What this does not stop

Stated plainly, because a control that is oversold is worse than one that is understood.

Each item below is executed as a test in
`tests/Integration/Extensibility/SandboxOpenGapsTest.php`, which asserts the gap is still
open and then marks itself incomplete. So this list is checked rather than asserted: if
one of these is ever closed, its test fails and whoever closed it has to correct this
section. Run `--group open-gap` to see exactly what the sandbox does not stop.

- **Reflection.** An extension runs in the same PHP process as the framework and can read
  `ScopedContainerProxy`'s private `$inner` property directly. PHP offers no way to
  withhold that, and the alternative considered above (process isolation) was rejected for
  reasons that have not changed. The tier system raises the cost and makes the intent
  auditable; it is not a memory boundary, and a hostile extension is still hostile code you
  chose to install. This one is not a decision that can be revisited without revisiting
  process isolation.
- **Reading the route table.** `ScopedRouterProxy::routes()` and `match()` delegate, so an
  extension can enumerate every path the host registered, `/admin` included, along with
  each route name and handler — including a handler closure the host defined, which the
  extension can then invoke with arguments of its choosing. That is disclosure plus the
  reach of whatever the host put in a closure; registration itself is still confined.
  Closing it means deciding what a filtered route table returns for link generation,
  diagnostics and an extension'"'"'s own introspection, all of which expect the whole table.
- **Dispatching events.** `Psr\EventDispatcher\EventDispatcherInterface` is safe-listed,
  so a Community extension can fire any event the host listens for, and a forged domain
  event will be acted on by whatever listens. Adding a LISTENER is a separate power costing
  `ServiceRegister`, so Untrusted cannot and Community and above can. Closing the dispatch
  half needs per-event-type authorization, which the framework does not have; removing the
  dispatcher from the safe list would break substantially every extension.
- **Redefining a role the host already registered.** `RoleRegistryInterface` costs
  `ServiceRegister`, and the registry is last-write-wins by design — `RoleSeeder` relies on
  it to let database roles supersede configured ones. So an extension holding that
  capability can register a role under a name the host already used and change the
  permissions the gate answers with for it. Refusing the registry does not make this safer:
  it makes an extension ship its features with no permissions defined at all, which is why
  `pulsar/forum` declares `forum.viewer` through `forum.admin` there. Closing it means
  first-wins semantics or a per-extension role namespace, both of which change what
  `RoleSeeder` does, and neither is a sandbox decision.

Two entries were on this list and are no longer, because the code now does what the table
said:

- `MiddlewareRegister`, `CommandRegister` and `AuditWrite` were declared, granted, and
  consulted by nothing anywhere in `src/`. They now have enforcement sites — the middleware
  pipeline and registry, `ExtensionBootstrap::buildCommand()`, and the audit logger
  respectively.
- Route capabilities still cannot be granted per-extension: `ScopedRouterProxy` answers from
  the tier and the policy alone, so `additional_capabilities` naming `RouteRegister` or
  `RouteRegisterGlobal` has no effect and reports no error. The failure is a REFUSAL — the
  host gets less than it asked for, never more — so the boundary holds. Raising the tier is
  the supported route. `ScopedRouterProxyTest::routeCapabilitiesCannotBeGrantedPerExtension`
  fails if that ever changes.

## Performance impact

Two costs, and they are not the same cost.

**At boot.** One `isset()` in the restriction map and one `in_array()` in the capability
policy per resolution, both O(1). Core-tier extensions bypass the proxy entirely and pay
nothing. `ScopedRouterProxy::add()` additionally scans the host's route table for a name
collision, which is O(routes) per extension route registered - at the scale the framework
boots at (538 routes with every bundled extension enabled) that is a few hundred thousand
string comparisons across the whole boot, once.

An id that reaches deny-by-default is asked two further questions — does this extension
SHIP the type, does another extension PUBLISH it — and both are answered by the file the
type is declared in, so both reflect and `realpath()`. Each extension's own directory is
resolved once in the scope's constructor and the peer roots once per bootstrap, leaving one
reflection and one `realpath()` per distinct id. Measured on this repository, a cold boot
with all thirteen bundled products enabled takes ~3.0 s sandboxed against ~2.5 s with the
same extensions at Core — half a second for the whole sandbox across thirteen extensions,
against a boot dominated by autoloading, and before `pulsar optimize`. `has()` answering
for the scope rather than delegating is what moved this from an error path to a warm one,
which is why the roots are hoisted rather than recomputed.

**At request time.** This section used to say "no impact on hot-path performance -
capability checks happen during bootstrap, not during request handling", and containment
by construction made that false. A route handler, a route middleware and a CLI command are
classes the extension NAMES and the framework CONSTRUCTS long after boot, so the scope
binds a factory for them at registration time and the construction runs inside it - which
means the restriction map and the capability policy are consulted once per constructor
parameter, on the request that first resolves that handler. It is per resolution rather
than per request (the container caches a singleton like any other), it applies only to
proxied tiers, and it is the price of the handler not being built by the real container
from the real graph. Stating it is the point: a control that runs in the hot path should
be described as one.

## Migration / rollback plan

**Adoption (gradual):**

1. All first-party extensions tagged `"trust_tier": "core"` in `pulsar.json`.
2. Host creates `config/extensions.php` with trusted extensions allow-list.
3. When `CapabilityPolicy` is set on `ExtensionBootstrap`, enforcement activates.
4. Without a policy (default), extensions behave exactly as before - full access.

**Rollback:**

1. Remove the `setCapabilityPolicy()` / `setTrustedExtensionsConfig()` calls from the application bootstrap.
2. Extensions revert to full container/router access.
3. No data migration, schema changes, or manifest updates required (the `trust_tier` field is ignored when no policy is active).

## Links

- ADR-0047: A tier is granted, never claimed — closes the resolution path that returned a
  manifest's requested tier verbatim when no host allow-list was attached, and records what
  does and does not verify an extension (nothing signs one)
- ADR-0004: Extension-First Architecture with Manifest-Driven Lifecycle
- ADR-0009: Attribute-Based Public API Surface
- ADR-0013: Boundary Enforcement via API Interfaces
- OWASP Supply Chain Security
- MITRE ATT&CK: Supply Chain Compromise (T1195)
