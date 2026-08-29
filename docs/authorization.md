# Authorization

Pulsar provides a hybrid RBAC + ABAC authorization system through the Gate. Roles define sets of permissions (RBAC), and policies provide context-aware overrides (ABAC). Authorization integrates with the middleware pipeline via `AuthorizationMiddleware`.

## Architecture

### Evaluation order

The Gate evaluates authorization in five steps:

1. **Super-role bypass** - Roles in the configured `super_roles` list are always allowed.
2. **ABAC explicit deny** - Any policy returning `false` immediately denies access.
3. **RBAC check** - Role-to-permission matching via the `RoleRegistry`.
4. **ABAC explicit allow** - Any policy returning `true` grants access (even without RBAC match).
5. **Default deny** - No match results in denial.

Explicit deny always wins. A policy returning `false` overrides RBAC permissions.

```
allows(identity, permission, ?context)
  │
  ├─ identity has super-role? → ALLOW
  │
  ├─ any policy returns false? → DENY
  │
  ├─ RBAC: role has permission? → ALLOW
  │
  ├─ any policy returns true? → ALLOW
  │
  └─ default → DENY
```

## Configuration

### config/security.php

```php
'authorization' => [
    'roles' => [
        'admin' => [
            'permissions' => ['*'],     // Wildcard: all permissions
        ],
        'editor' => [
            'permissions' => ['content.view', 'content.create', 'content.edit'],
        ],
        'viewer' => [
            'permissions' => ['content.view'],
        ],
    ],
    'super_roles' => ['admin'],
],
```

### AuthorizationConfig DTO

```php
readonly class AuthorizationConfig
{
    public function __construct(
        /** @var array<string, array<string, mixed>> */
        public array $roles = [],
        /** @var list<string> */
        public array $superRoles = [],
    ) {}

    public static function fromArray(array $data): self;
}
```

## Permissions

### Permission value object

```php
$perm = new Permission('content.edit');
$perm->matches('content.edit');    // true
$perm->matches('content.delete');  // false
```

### Wildcard matching

Permissions support wildcard patterns:

| Pattern        | Matches                          | Does Not Match |
| -------------- | -------------------------------- | -------------- |
| `*`            | Everything                       | -              |
| `content.*`    | `content.view`, `content.create` | `users.view`   |
| `content.view` | `content.view`                   | `content.edit` |

Wildcards apply at the dot-separated segment level. `content.*` matches any permission starting with `content.`.

## Roles

### Role value object

A role is a named collection of permissions:

```php
$role = new Role(
    name: 'editor',
    permissions: [
        new Permission('content.view'),
        new Permission('content.create'),
        new Permission('content.edit'),
    ],
);

$role->hasPermission('content.view');    // true
$role->hasPermission('content.delete');  // false
```

### Building from config

```php
$role = Role::fromArray('editor', [
    'permissions' => ['content.view', 'content.create', 'content.edit'],
]);
```

## Role registry

### RoleRegistryInterface

```php
interface RoleRegistryInterface
{
    public function findByName(string $name): ?Role;
    /** @return list<Permission> */
    public function permissionsForRoles(array $roleNames): array;
    public function register(Role $role): void;
}
```

### InMemoryRoleRegistry

In-memory implementation populated from config at boot time:

```php
$registry = new InMemoryRoleRegistry();

$registry->register(new Role('admin', [new Permission('*')]));
$registry->register(new Role('editor', [
    new Permission('content.view'),
    new Permission('content.create'),
]));

$role = $registry->findByName('editor');         // Role instance
$perms = $registry->permissionsForRoles(['editor', 'viewer']); // Merged permissions
```

`permissionsForRoles()` merges permissions from all specified roles. Unknown role names are silently skipped.

## Gate

### GateInterface

```php
interface GateInterface
{
    public function allows(
        IdentityInterface $identity,
        string $permission,
        ?PolicyContext $context = null,
    ): bool;

    public function denies(
        IdentityInterface $identity,
        string $permission,
        ?PolicyContext $context = null,
    ): bool;
}
```

`denies()` is the inverse of `allows()`.

### Gate construction

```php
$gate = new Gate(
    roleRegistry: $registry,
    superRoles: ['superadmin'],
    decisionSink: $sink,
    logger: $logger,
);
```

The sink is not decoration. `Gate::allows()` hands every grant and every refusal to its `AuthorizationDecisionSinkInterface`, and records nothing when none was supplied — a Gate built without one reaches its decisions silently, and the one decision an assessor asks to see leaves no trace anywhere. The framework's `AuthWiring` supplies one; see [Decision audit trail](#decision-audit-trail).

It is a single named collaborator rather than the application's event dispatcher, and that is the point. A dispatcher runs every listener the application happens to have registered inside every authorization decision: one that throws turns a grant into a `500`, one that is slow makes every check slow, and one that asks the Gate a question re-enters the decision it is being told about. What runs inside a decision is a wiring fact, not a consequence of what else the application subscribed to.

The Gate contains a sink that misbehaves anyway. Everything the sink raises is caught and reported at `critical` — a transient audit fault must not change an authorization outcome — and the report itself cannot raise, so a logger having a worse day than the sink cannot undo the containment one frame further out. A sink that calls back into `allows()` is refused a nested recording frame: the decision it caused is queued and written after the outer one, up to a ceiling of 16 per decision, past which further nesting is refused and reported. Neither is a licence; both are containment.

That frame is scoped to the call stack that opened it, not to the Gate. Under a persistent runtime the shipped sink reaches an `AuditLogger` that spins cooperatively on its chain lock, so a sink can and does suspend the Fiber inside a record — and a guard held on the Gate would read a second Fiber's decision as the first one re-entering, queue it against the wrong decision, count it against the wrong ceiling and drop it past that ceiling. The guard is held in a `WeakMap` keyed by `Fiber::getCurrent()`, the way `RequestContextHolder` and `TenantContext` hold theirs, so concurrent decisions are neither cross-attributed nor lost.

### RBAC usage

```php
$admin = new Identity(id: 'admin-1', displayName: 'Admin', roles: ['admin']);
$editor = new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']);

$gate->allows($admin, 'users.delete');     // true (wildcard *)
$gate->allows($editor, 'content.edit');    // true
$gate->allows($editor, 'users.delete');    // false
```

### Multiple roles

When an identity has multiple roles, permissions are merged:

```php
$multi = new Identity(id: 'u-1', displayName: 'Multi', roles: ['editor', 'viewer']);

$gate->allows($multi, 'content.view');     // true (both roles)
$gate->allows($multi, 'content.create');   // true (editor)
$gate->allows($multi, 'users.delete');     // false (neither role)
```

### Super roles

Super roles bypass all RBAC and ABAC checks:

```php
$gate = new Gate($registry, superRoles: ['superadmin']);

$super = new Identity(id: 's-1', displayName: 'Super', roles: ['superadmin']);
$gate->allows($super, 'anything');  // true - always
```

## ABAC policies

### PolicyInterface

Policies provide context-aware authorization decisions that can override RBAC:

```php
interface PolicyInterface
{
    public function evaluate(IdentityInterface $identity, PolicyContext $context): ?bool;
}
```

Return values:

| Return  | Meaning                                    |
| ------- | ------------------------------------------ |
| `true`  | Explicit allow (can grant without RBAC)    |
| `false` | Explicit deny (overrides RBAC permissions) |
| `null`  | Abstain (no opinion, continue evaluation)  |

### PolicyContext

```php
$context = new PolicyContext(
    permission: 'content.edit',
    resource: '/articles/42',
    attributes: ['owner_id' => 'user-5'],
);
```

### Registering policies

```php
$gate->addPolicy($policy);
```

### Example: resource ownership policy

```php
final readonly class OwnershipPolicy implements PolicyInterface
{
    public function evaluate(IdentityInterface $identity, PolicyContext $context): ?bool
    {
        // Only apply to content.edit and content.delete
        if (!str_starts_with($context->permission, 'content.')) {
            return null; // Abstain for unrelated permissions
        }

        $ownerId = $context->attributes['owner_id'] ?? null;
        if ($ownerId === null) {
            return null; // No ownership info, abstain
        }

        // Allow owners to edit their own content
        if ($ownerId === $identity->id()) {
            return true;
        }

        return null; // Not the owner, let RBAC decide
    }
}
```

### Example: locked resource policy

```php
final readonly class LockedResourcePolicy implements PolicyInterface
{
    public function evaluate(IdentityInterface $identity, PolicyContext $context): ?bool
    {
        // Deny edits to locked resources regardless of RBAC
        if ($context->permission === 'content.edit' && $context->resource === '/locked-article') {
            return false; // Explicit deny - overrides RBAC
        }

        return null;
    }
}
```

### Gate with ABAC

```php
$gate = new Gate($registry);
$gate->addPolicy(new OwnershipPolicy());
$gate->addPolicy(new LockedResourcePolicy());

$editor = new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']);

// Normal article: RBAC allows content.edit for editor
$gate->allows($editor, 'content.edit', new PolicyContext(
    permission: 'content.edit',
    resource: '/normal-article',
));
// → true

// Locked article: policy denies despite RBAC
$gate->allows($editor, 'content.edit', new PolicyContext(
    permission: 'content.edit',
    resource: '/locked-article',
));
// → false
```

## Route integration

### Declaring permissions on routes

Permissions are declared via the `attributes` parameter on `Route`:

```php
$route = new Route(
    methods: [Method::GET],
    path: '/admin/users',
    handler: [UserController::class, 'index'],
    attributes: ['permissions' => ['users.view']],
    middleware: ['auth'],
);
```

Multiple permissions can be required - all must be granted:

```php
$route = new Route(
    methods: [Method::POST],
    path: '/admin/users',
    handler: [UserController::class, 'create'],
    attributes: ['permissions' => ['users.view', 'users.create']],
    middleware: ['auth'],
);
```

### AuthorizationMiddleware

The `auth` middleware alias maps to `AuthorizationMiddleware`:

1. Reads `_security_context` from request attributes
2. Calls `SecurityContext::identity()` to trigger lazy authentication
3. Returns `401 Unauthorized` if the identity is not authenticated, and counts the refusal
4. Reads `permissions` from the route the **kernel is dispatching**
5. Checks each permission against the Gate
6. Returns `403 Forbidden` if any permission check fails
7. Passes the request to the next handler if all permissions are granted

**Step 4 does not read the `_route` request attribute.** This is a route-level middleware, so
other route middleware runs in front of it, and every one of them can hand the next frame a
request carrying whatever `_route` it likes while the kernel goes on dispatching the route it
matched. A substituted route declaring `permissions: ['_authenticated']` — the documented "any
authenticated identity" sentinel — therefore skipped the real route's permissions entirely: the
Gate was never asked, and the handler ran. The route arrives as an argument instead, through
`DispatchedRouteAwareInterface`, which the pipeline binds before the middleware chain is built
and therefore before any frame that could write an attribute exists (ADR-0051, ADR-0053).

**An unauthenticated refusal is counted, not chained.** Step 3 used to write a full entry into
the HMAC-chained audit log: actor `anonymous`, action `authenticate`, reason `unauthenticated`,
and `resource` set to the requested path. Every such entry says the same thing, names nobody,
and its only varying column is chosen by the caller — so an unauthenticated client could grow
the tamper-evident chain without limit, one HMAC advance and one `LOCK_EX` append per request,
and bury the denials that do name an actor. What is recorded now is
`pulsar_auth_anonymous_denials_total`, labelled with the **route** and the reason — both bounded
by the route table — plus the occurrence in the application log at `debug` and in the access log
every deployment already keeps. Metrics are on by default and `MetricsWiring` runs before `AuthWiring`, so the counter exists
in a default deployment; with metrics disabled it is absent, the `debug` line and the access log
are what remain, and the 401 is unchanged either way. `AuthWiring` publishes no wiring contract,
so that degradation is documented here rather than reported by the wiring-contract inspector.

A denial that names an actor is unchanged: full entry, one per occurrence, no ceiling. Its volume
is bounded by the number of credentials in existence, and whoever floods it is named in every
line they add.

If no `permissions` attribute is set on the route, the middleware **denies** with `403 Forbidden` and audits the denial with the reason `no_permissions_declared`. An empty permission list is not a grant: falling through would let every authenticated identity past with no authorization check at all. Where the grant genuinely is "any authenticated identity", say so with the `_authenticated` sentinel (`RouteAccessRegistrar::ANY_AUTHENTICATED`).

The same fail-closed rule applies when the request carries no matched route at all: without route context the required permissions are unknown, so the request is refused with the reason `no_route_context`.

This is why a route that declares nothing has no fixed posture. `auth` is a route-level alias, not a global middleware, so an undeclared route is reachable by anyone in a deployment whose pipeline omits it and reachable by nobody in one that includes it. See [Declaring who may reach a route](#declaring-who-may-reach-a-route).

### Declaring who may reach a route

Every route the framework registers states its access decision at the registration site, through `Pulsar\Routing\RouteAccessRegistrar`. The decision is stored on the route as a `Pulsar\Routing\RouteAccess` case plus a one-line reason, so it is readable from the route table rather than only from the wiring source.

The cases name what enforces the access, not merely who has it:

| Case            | Enforced by                                | Typical use                                                   |
| --------------- | ------------------------------------------ | ------------------------------------------------------------- |
| `Public`        | nothing, deliberately                      | assets, health probes, i18n bundles, anti-spam widget scripts |
| `Operator`      | a Bearer-token guard inside the handler    | `/_pulsar/diagnostics`, the OpenMetrics exporter              |
| `Signed`        | a provider signature over the request body | inbound mail webhooks                                         |
| `Authenticated` | the `auth` alias plus a named permission   | anything identity-bound                                       |

```php
use Pulsar\Http\Method;
use Pulsar\Routing\RouteAccessRegistrar;

$routes = new RouteAccessRegistrar($router, $middlewareRegistry, $logger);

$routes->publicRoute(
    [Method::GET],
    '/health',
    $healthHandler,
    'pulsar.health',
    'Liveness probe for load balancers, which carry no credential.',
);

$routes->authenticated(
    [Method::GET],
    '/admin/users',
    [UserController::class, 'index'],
    'admin.users.index',
    ['users.view'],
    'Lists every account in the tenant.',
);
```

`authenticated()` refuses two shapes rather than registering them:

- **An empty permission list throws.** `AuthorizationMiddleware` reads it as deny-everyone, so the route would be unreachable for every caller — a broken feature wearing the appearance of a guarded one. Use `RouteAccessRegistrar::ANY_AUTHENTICATED` when the grant really is "any authenticated identity".
- **A missing `auth` alias skips the registration.** With `security.auth` unset, `AuthWiring` returns before publishing the alias and nothing would enforce the permission list. The route is not registered and the omission is logged: `404` is the honest answer for a feature whose authorization is absent.

`Operator` routes deliberately do **not** name the `auth` alias. Their callers are metrics scrapers and on-call engineers, who carry a Bearer token rather than a session, and the guard runs inside the handler so the refusal does not depend on which middleware the deployment piped.

A route declared `RouteAccess::Public` is treated exactly like a handler carrying `#[PublicRoute]` by [route model binding](route-model-binding.md): a regulated preset will not force it through mandatory authorization.

#### The boot-time check

`Pulsar\Routing\RouteAccessReporter` runs at the end of boot, beside the route-collision reporter, once every framework wiring, extension and project route file has registered. A framework route that declares no `RouteAccess` produces a warning in production and aborts the boot in debug.

It judges framework routes only — handlers under `Pulsar\`, excluding the namespaces this repository reserves for code the framework does not ship (`Pulsar\Extension\` for extensions, `Pulsar\Tests\` for its own test fixtures), plus closure handlers under the reserved `/_pulsar/` prefix. Application and extension routes are the application's and the extension's to decide.

The declaration survives `pulsar optimize --strict`: route attributes are part of the cached route payload, and the cache's deserialization allowlist is derived from the serialized bytes.

### Audit logging

`AuthorizationMiddleware` accepts an optional `AuditLogger` for recording authorization decisions:

```php
$middleware = new AuthorizationMiddleware(
    gate: $gate,
    auditLogger: $auditLogger,  // Optional
);
```

When provided, authorization denials reached through the middleware are logged as `AuditEvent::Authorization` with `AuditOutcome::Denied`.

### Decision audit trail

Every decision the Gate reaches — including the ones made by a policy or a service calling `allows()` directly, which never pass through the middleware — is recorded. `AuthWiring` builds `BufferedAuthorizationDecisionSink` whenever an audit logger exists to write to, and an application that binds its own `AuthorizationDecisionSinkInterface` keeps that one instead.

Each entry is written to the HMAC-chained audit log as `AuditEvent::Authorization`, with `AuditOutcome::Success` for `authorization.granted` and `AuditOutcome::Denied` for `authorization.denied`, and carries:

| Metadata key     | Meaning                                                                                  |
| ---------------- | ---------------------------------------------------------------------------------------- |
| `permission`     | The permission checked                                                                   |
| `reason`         | `super-role`, `RBAC`, `ABAC`, `ABAC-deny`, or `default-deny`                             |
| `decision_nonce` | CSPRNG nonce; two entries sharing one are the same decision written twice                |
| `decided_at`     | When the decision was reached, which is not when the entry was written                   |
| `correlation_id` | The request the decision was made in, captured at decision time                          |
| `payload_hash`   | Hash over the `AuthorizationGranted` / `AuthorizationDenied` payload the entry describes |

Three properties make the entry usable as evidence rather than as a log line. Tamper evidence comes from the chain: each entry is HMAC-chained to its predecessor, so an altered or excised decision breaks verification. Forgery resistance for the payload comes from `payload_hash`: fields rewritten after the fact no longer hash to the recorded value. Replay detection comes from `decision_nonce`.

A failure to write never unwinds the decision that caused it — the sink reports at `critical` to the application log and returns, so a transient sink fault cannot turn an authorization check into a failed request. Nor can a failure to report: every `critical` this path makes is itself wrapped, because the logger is application-supplied and the alternative is a shutdown-time throw out of the sink's destructor. One record the chain rejects does not cost the trail the records behind it.

#### When the entry is written

Chaining an audit entry costs roughly ten times what reaching the decision costs, so it does not happen inside the decision. `record()` captures the decision and the request context it was made in and returns; the entries are built and chained at a **drain point**, which is a moment no decision is in flight. There are four, and `AuthWiring` registers one listener — `AuthorizationDecisionFlushListener` — on the first three:

- the kernel's `TerminateEvent`, after the response has gone out — what a served HTTP request uses;
- the queue's `JobCompleted` and `JobFailed`, between jobs — what a worker uses, because a worker never reaches `Kernel::terminate()`;
- the sink being destroyed — what a console command and a runtime that never calls `Kernel::terminate()` use. PHP runs it on normal shutdown, on `exit()`, and after an uncaught exception the kernel has rendered.

```php
// config/security.php -> auth.authorization
'decision_audit_buffer' => 1024,   // 1 writes each decision through as it is reached
```

`decision_audit_buffer` is how many decisions the sink may **hold**, not how many it batches. It is the memory ceiling behind the drain points, and reaching it means one of them should have run and did not — a unit of work authorizing more than a thousand actions, or a process with no drain point at all. The sink says so once at `critical` and then chains one entry, the oldest, per further decision: memory stays pinned, the trail stays in order, and what lands inside a decision in that state is a single write rather than a whole buffer.

`1` is the exception, and it is a configuration rather than a defect: capacity one is write-through, chosen by a deployment that will not accept a durability window, and it puts the chained write inside every authorization check by design.

What that trades is bounded and configured. A decision captured but not yet written is one a hard process death — a segfault, an OOM, a `kill -9` — would lose. Nothing softer than that loses it: an uncaught exception is rendered into a response and the process exits normally. What bounds the loss is the distance to the next drain point: for a served request that is the request, for a worker the job. `1` closes the window entirely.

Measured on one machine (PHP 8.5 ZTS, xdebug off, opcache on; 8 000 revolutions per round, variants interleaved round-robin, median of three runs of 15-31 rounds; microseconds per decision):

| Gate                                                            | allow | deny |
| --------------------------------------------------------------- | ----- | ---- |
| Recording nothing                                               | 4.0   | 3.9  |
| Recording through the application's event dispatcher            | 58    | 58   |
| ...with twelve unrelated listeners also registered              | 60    | —    |
| Recording through the buffered sink                             | 4.3   | 4.6  |
| Recording through the buffered sink, `decision_audit_buffer: 1` | 60    | —    |
| Evaluation alone, no sink bound                                 | 2.1   | —    |

A decision that records is back on the same order as one that recorded nothing — a grant within a tenth of the old cost, a refusal within a fifth — against a fourteen-fold regression on the mechanism this replaces. The evaluation itself halved, which is what pays for the record: a default `PolicyContext` is no longer built for the deployments with no ABAC policy to hand it to, the super-role walk is skipped when no super roles are configured, and both permission scans are `foreach` rather than `array_any` with a closure.

The dispatcher's cost rose with whatever else the application had registered; the sink's does not, because nothing else is on the path. `AuthorizationBench::benchGateAllowsRecordingDecisions` holds the budget, and builds its sink from `AuthorizationConfig::DEFAULT_DECISION_AUDIT_BUFFER`, so a change that puts the write back inside the decision fails a benchmark rather than a deployment.

The table above is a single decision. What a request pays is the other half of the picture, because the buffer used to flush from inside `record()` the moment it reached its threshold — so a request authorizing more actions than the threshold paid for a whole buffer of chained entries inside one arbitrary check. Measured as a request (PHP 8.5.9, xdebug off, opcache on; 400 requests per variant, medians across requests; the drain is not counted against a decision, because it is not one):

| Request                | Gate                                | mean/decision | worst decision | authz per request |
| ---------------------- | ----------------------------------- | ------------- | -------------- | ----------------- |
| ordinary (8 decisions) | drain-point write — what ships      | 5.17 µs       | 6.6 µs         | 41.4 µs           |
| ordinary (8 decisions) | threshold flush inside the decision | 6.58 µs       | 10.7 µs        | 52.6 µs           |
| listing page (200)     | drain-point write — what ships      | 6.31 µs       | 36.6 µs        | 1.26 ms           |
| listing page (200)     | threshold flush inside the decision | 60.52 µs      | 4.34 ms        | 12.10 ms          |

The ordinary request is parity; the listing page is the reason the flush is not in `record()`. Same total work, written where nothing is waiting on it.

The `AuthorizationGranted` / `AuthorizationDenied` events remain part of the public surface and are still what a decision is expressed as — the sink constructs one per decision and cites its payload hash. They are no longer dispatched to application listeners, so a listener registered for them will not be called.

## Middleware aliases

The Kernel registers these middleware aliases:

| Alias       | Middleware                     | Purpose                                               |
| ----------- | ------------------------------ | ----------------------------------------------------- |
| `auth`      | `AuthorizationMiddleware`      | Authentication + permission checks                    |
| `2fa`       | `TwoFactorMiddleware`          | Blocks pending 2FA status                             |
| `step-up`   | `StepUpMiddleware`             | Requires a recent step-up challenge (session-backed)  |
| `sensitive` | `SensitiveOperationMiddleware` | Account-takeover checks on credential-changing routes |
| `zerotrust` | `ZeroTrustMiddleware`          | Deny-by-default signal/policy gate (opt-in)           |

`step-up` and `sensitive` are registered only when the session-backed guard they depend on was built; see [Authentication](authentication.md#sensitiveoperationmiddleware-route-level).

The `zerotrust` alias is registered only when `security.zero_trust.enabled` is
true (see [Zero-trust access control](#zero-trust-access-control)); it is never
piped globally.

### Common route patterns

```php
// Public route - no middleware needed
new Route([Method::GET], '/public', $handler);

// Authenticated only - no specific permission
new Route([Method::GET], '/profile', $handler, middleware: ['auth']);

// Authenticated + specific permission
new Route([Method::GET], '/admin/users', $handler,
    attributes: ['permissions' => ['users.view']],
    middleware: ['auth'],
);

// Authenticated + 2FA verified + specific permission
new Route([Method::GET], '/admin/settings', $handler,
    attributes: ['permissions' => ['settings.manage']],
    middleware: ['auth', '2fa'],
);
```

## Zero-trust access control

The Gate (RBAC + ABAC) answers "may this identity perform this action?". The
zero-trust subsystem answers a different question — "do we still trust this
session _right now_?" — by scoring runtime signals (device, location, network,
time, behaviour) against a policy on every request. It is **opt-in** and
**deny-by-default**: a route guarded by `zerotrust` with no matching rule is
denied, so you enable it and grant access explicitly, never the reverse.

### Enabling it

Zero-trust is off unless configured. Wiring requires the event dispatcher and
audit logger (so every decision is announced and recorded); the crypto key ring
is used, when present, to pseudonymise retained signals.

```php
// config/security.php
return [
    // ...
    'zero_trust' => [
        'enabled' => true,
        'rules' => [
            [
                'name' => 'read-reports',
                'resource_pattern' => '/reports/*',
                'action' => 'read',
                'on_match' => 'grant',
                'on_no_match' => 'deny',
                'priority' => 10,
                'requirements' => [
                    ['claim' => 'device.registered', 'min_confidence' => 0.8],
                    ['claim' => 'network.trusted', 'min_confidence' => 0.6, 'allowed_sources' => ['network']],
                ],
            ],
        ],
    ],
];
```

Each rule maps a glob `resource_pattern` + `action` to a set of claim
`requirements`; `on_match` / `on_no_match` default to `grant` / `deny`, and
higher-`priority` rules are evaluated first. A requirement names a `claim`, a
`min_confidence` (0.0–1.0), and optionally the `allowed_sources` that may supply
it.

### Applying it to routes

Attach the `zerotrust` alias only to the routes it should guard. Because the
policy engine is deny-by-default, piping it globally would reject every
unmatched route the moment the feature is enabled.

```php
new Route([Method::GET], '/reports/{id}', $handler,
    middleware: ['auth', 'zerotrust'],
);
```

See [zero-trust threat model](security/zero-trust-threat-model.md) and
[compliance mapping](security/zero-trust-compliance-mapping.md) for the signal
model and the regulatory controls it satisfies.
