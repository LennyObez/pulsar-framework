# Authorization

Pulsar provides a hybrid RBAC + ABAC authorization system through the Gate. Roles define sets of permissions (RBAC), and policies provide context-aware overrides (ABAC). Authorization integrates with the middleware pipeline via `AuthorizationMiddleware`.

## Architecture

### Evaluation Order

The Gate evaluates authorization in five steps:

1. **Super-role bypass** — Roles in the configured `super_roles` list are always allowed.
2. **ABAC explicit deny** — Any policy returning `false` immediately denies access.
3. **RBAC check** — Role-to-permission matching via the `RoleRegistry`.
4. **ABAC explicit allow** — Any policy returning `true` grants access (even without RBAC match).
5. **Default deny** — No match results in denial.

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

### Permission Value Object

```php
$perm = new Permission('content.edit');
$perm->matches('content.edit');    // true
$perm->matches('content.delete');  // false
```

### Wildcard Matching

Permissions support wildcard patterns:

| Pattern        | Matches                          | Does Not Match |
| -------------- | -------------------------------- | -------------- |
| `*`            | Everything                       | —              |
| `content.*`    | `content.view`, `content.create` | `users.view`   |
| `content.view` | `content.view`                   | `content.edit` |

Wildcards apply at the dot-separated segment level. `content.*` matches any permission starting with `content.`.

## Roles

### Role Value Object

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

### Building from Config

```php
$role = Role::fromArray('editor', [
    'permissions' => ['content.view', 'content.create', 'content.edit'],
]);
```

## Role Registry

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

### Gate Construction

```php
$gate = new Gate(
    roleRegistry: $registry,
    superRoles: ['superadmin'],
);
```

### RBAC Usage

```php
$admin = new Identity(id: 'admin-1', displayName: 'Admin', roles: ['admin']);
$editor = new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']);

$gate->allows($admin, 'users.delete');     // true (wildcard *)
$gate->allows($editor, 'content.edit');    // true
$gate->allows($editor, 'users.delete');    // false
```

### Multiple Roles

When an identity has multiple roles, permissions are merged:

```php
$multi = new Identity(id: 'u-1', displayName: 'Multi', roles: ['editor', 'viewer']);

$gate->allows($multi, 'content.view');     // true (both roles)
$gate->allows($multi, 'content.create');   // true (editor)
$gate->allows($multi, 'users.delete');     // false (neither role)
```

### Super Roles

Super roles bypass all RBAC and ABAC checks:

```php
$gate = new Gate($registry, superRoles: ['superadmin']);

$super = new Identity(id: 's-1', displayName: 'Super', roles: ['superadmin']);
$gate->allows($super, 'anything');  // true — always
```

## ABAC Policies

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

### Registering Policies

```php
$gate->addPolicy($policy);
```

### Example: Resource Ownership Policy

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

### Example: Locked Resource Policy

```php
final readonly class LockedResourcePolicy implements PolicyInterface
{
    public function evaluate(IdentityInterface $identity, PolicyContext $context): ?bool
    {
        // Deny edits to locked resources regardless of RBAC
        if ($context->permission === 'content.edit' && $context->resource === '/locked-article') {
            return false; // Explicit deny — overrides RBAC
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

## Route Integration

### Declaring Permissions on Routes

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

Multiple permissions can be required — all must be granted:

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
3. Returns `401 Unauthorized` if the identity is not authenticated
4. Reads `permissions` from the matched route's attributes
5. Checks each permission against the Gate
6. Returns `403 Forbidden` if any permission check fails
7. Passes the request to the next handler if all permissions are granted

If no `permissions` attribute is set on the route, the middleware passes through (acts as authentication-only check).

### Audit Logging

`AuthorizationMiddleware` accepts an optional `AuditLogger` for recording authorization decisions:

```php
$middleware = new AuthorizationMiddleware(
    gate: $gate,
    auditLogger: $auditLogger,  // Optional
);
```

When provided, authorization denials are logged as `AuditEvent::Authorization` with `AuditOutcome::Denied`.

## Middleware Aliases

The Kernel registers these middleware aliases:

| Alias  | Middleware                | Purpose                            |
| ------ | ------------------------- | ---------------------------------- |
| `auth` | `AuthorizationMiddleware` | Authentication + permission checks |
| `2fa`  | `TwoFactorMiddleware`     | Blocks pending 2FA status          |

### Common Route Patterns

```php
// Public route — no middleware needed
new Route([Method::GET], '/public', $handler);

// Authenticated only — no specific permission
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
