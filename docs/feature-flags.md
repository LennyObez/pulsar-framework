# Feature flags

## Overview

Pulsar includes a built-in feature flag system designed for regulated environments where controlled rollouts, audit trails, and deterministic evaluation are mandatory. Feature flags enable progressive delivery, A/B testing, tenant-specific features, and environment-gated functionality - all without code deployments.

The system supports three flag types (boolean, percentage, contextual), two storage backends (in-memory, file-based JSON), and full evaluation audit logging suitable for compliance reporting.

### Key components

| Class                         | Namespace                      | Purpose                                            |
| ----------------------------- | ------------------------------ | -------------------------------------------------- |
| `FeatureFlagManager`          | `Pulsar\FeatureFlag`           | Core evaluation engine                             |
| `FeatureFlagManagerInterface` | `Pulsar\FeatureFlag`           | Contract for flag evaluation                       |
| `FlagDefinition`              | `Pulsar\FeatureFlag`           | Immutable flag definition value object             |
| `FlagType`                    | `Pulsar\FeatureFlag`           | Enum: `Boolean`, `Percentage`, `Contextual`        |
| `FlagContext`                 | `Pulsar\FeatureFlag`           | Evaluation context (tenant, user, environment)     |
| `FlagEvaluation`              | `Pulsar\FeatureFlag`           | Immutable evaluation result record                 |
| `FlagEvaluationReason`        | `Pulsar\FeatureFlag`           | Enum describing why a flag evaluated to its result |
| `FlagEvaluationLog`           | `Pulsar\FeatureFlag`           | In-memory audit log of all evaluations             |
| `FlagStorageInterface`        | `Pulsar\FeatureFlag`           | Contract for flag definition storage               |
| `FlagStorageDriver`           | `Pulsar\FeatureFlag`           | Enum: `Memory`, `File`                             |
| `InMemoryFlagStorage`         | `Pulsar\FeatureFlag\Storage`   | In-memory storage backend                          |
| `FileFlagStorage`             | `Pulsar\FeatureFlag\Storage`   | JSON file storage backend                          |
| `FeatureFlagConfig`           | `Pulsar\Config`                | Typed configuration DTO                            |
| `FeatureFlagException`        | `Pulsar\FeatureFlag\Exception` | Exception type for flag errors                     |

---

## Configuration reference

All feature flag settings live in `config/features.php`. The `FEATURE_FLAGS_ENABLED` environment variable overrides the `enabled` key.

```php
// config/features.php
return [
    'enabled' => false,

    // Storage driver: 'memory' or 'file'
    'storage' => 'memory',

    // Path to the JSON file when storage is 'file'
    'file_path' => 'storage/flags.json',

    // When true, every flag evaluation is recorded in the audit log
    'audit_evaluations' => false,

    // Default evaluation result when a flag is not found
    'default_state' => false,

    // Pre-configured flag definitions loaded at boot time
    'flags' => [
        'dark-mode' => [
            'enabled' => true,
            'type' => 'boolean',
            'description' => 'Enable dark mode UI',
        ],
        'new-checkout' => [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 25,
            'description' => 'Gradual rollout of new checkout flow',
        ],
        'beta-dashboard' => [
            'enabled' => true,
            'type' => 'contextual',
            'allowed_tenants' => ['acme', 'globex'],
            'allowed_users' => ['user-001'],
            'allowed_environments' => ['staging'],
            'description' => 'Beta dashboard for select tenants',
        ],
    ],
];
```

### Configuration DTO

```php
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\FeatureFlag\FlagStorageDriver;

readonly class FeatureFlagConfig
{
    public function __construct(
        public bool $enabled = false,
        public FlagStorageDriver $storage = FlagStorageDriver::Memory,
        public string $filePath = 'storage/flags.json',
        public bool $auditEvaluations = false,
        public bool $defaultState = false,
        public array $flags = [],
    ) {}
}
```

---

## Flag types

### Boolean

The simplest flag type. When the flag exists and is enabled, it evaluates to `true`. When disabled, it evaluates to `false`.

```php
'dark-mode' => [
    'enabled' => true,
    'type' => 'boolean',
    'description' => 'Enable dark mode UI',
],
```

**Evaluation logic:**

- If the flag is not found: returns `defaultState` with reason `FlagNotFound`.
- If the flag is disabled (`enabled: false`): returns `false` with reason `FlagDisabled`.
- If the flag is enabled: returns `true` with reason `FlagEnabled`.

### Percentage

Rolls a feature out to a deterministic subset of users or tenants. The percentage value (0-100) controls how many identifiers receive the feature. The evaluation is deterministic - the same user/tenant always gets the same result for a given flag.

```php
'new-checkout' => [
    'enabled' => true,
    'type' => 'percentage',
    'percentage' => 25,
    'description' => 'Gradual rollout of new checkout flow',
],
```

**Deterministic percentage algorithm:**

```
identifier = context.userId ?? context.tenantId ?? ''
hash       = crc32(flagName . identifier)
bucket     = ((hash % 100) + 100) % 100
result     = bucket < percentage
```

The double-modulo operation `((hash % 100) + 100) % 100` ensures a non-negative bucket value regardless of `crc32` sign. This produces a stable, uniformly distributed assignment that does not change between evaluations for the same identifier.

**Evaluation reasons:**

- `PercentageRollout`: the identifier fell within the rollout percentage.
- `PercentageExcluded`: the identifier fell outside the rollout percentage.

### Contextual

Evaluates based on the provided `FlagContext`. Allows targeting specific tenants, users, or environments. The evaluation checks each dimension in order: tenant, user, environment. The first match wins.

```php
'beta-dashboard' => [
    'enabled' => true,
    'type' => 'contextual',
    'allowed_tenants' => ['acme', 'globex'],
    'allowed_users' => ['user-001', 'user-042'],
    'allowed_environments' => ['staging', 'canary'],
    'description' => 'Beta dashboard for select tenants and users',
],
```

**Evaluation order and reasons:**

1. If `context.tenantId` is set and appears in `allowedTenants`: `true` with reason `TenantMatch`.
2. If `context.userId` is set and appears in `allowedUsers`: `true` with reason `UserMatch`.
3. If `context.environment` is set and appears in `allowedEnvironments`: `true` with reason `EnvironmentMatch`.
4. If none match: returns `defaultState` with reason `DefaultState`.

---

## FlagDefinition

The `FlagDefinition` readonly class holds all data for a single feature flag.

```php
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagType;

// Direct construction
$flag = new FlagDefinition(
    name: 'new-checkout',
    enabled: true,
    type: FlagType::Percentage,
    percentage: 25,
    allowedTenants: [],
    allowedUsers: [],
    allowedEnvironments: [],
    description: 'Gradual rollout of new checkout flow',
);

// From a raw config array
$flag = FlagDefinition::fromArray('new-checkout', [
    'enabled' => true,
    'type' => 'percentage',
    'percentage' => 25,
    'description' => 'Gradual rollout of new checkout flow',
]);

// Serialize back to array (for file storage)
$data = $flag->toArray();
```

### Properties

| Property              | Type           | Default   | Description                               |
| --------------------- | -------------- | --------- | ----------------------------------------- |
| `name`                | `string`       | --        | Unique flag identifier                    |
| `enabled`             | `bool`         | --        | Master on/off switch                      |
| `type`                | `FlagType`     | `Boolean` | Evaluation strategy                       |
| `percentage`          | `int`          | `100`     | Rollout percentage (percentage type only) |
| `allowedTenants`      | `list<string>` | `[]`      | Tenant IDs for contextual matching        |
| `allowedUsers`        | `list<string>` | `[]`      | User IDs for contextual matching          |
| `allowedEnvironments` | `list<string>` | `[]`      | Environment names for contextual matching |
| `description`         | `string`       | `''`      | Human-readable description                |

---

## Storage backends

### InMemoryFlagStorage

**Class:** `Pulsar\FeatureFlag\Storage\InMemoryFlagStorage`

Stores flag definitions in a PHP array. Data is lost when the process terminates. This is the default storage driver and is suitable for flags defined in configuration that are loaded at boot time.

```php
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;

$storage = new InMemoryFlagStorage();
$storage->set($flagDefinition);
$flag = $storage->get('dark-mode');        // FlagDefinition|null
$all = $storage->all();                     // array<string, FlagDefinition>
$exists = $storage->has('dark-mode');       // bool
$storage->remove('dark-mode');
```

### FileFlagStorage

**Class:** `Pulsar\FeatureFlag\Storage\FileFlagStorage`

Persists flag definitions to a JSON file on disk. Reads are cached in memory for the lifetime of the storage instance; writes atomically update the file using `LOCK_EX`.

```php
use Pulsar\FeatureFlag\Storage\FileFlagStorage;

$storage = new FileFlagStorage('storage/flags.json');
$storage->set($flagDefinition);  // Writes to disk immediately
$flag = $storage->get('dark-mode');
```

**JSON file format:**

```json
{
  "dark-mode": {
    "enabled": true,
    "type": "boolean",
    "percentage": 100,
    "allowed_tenants": [],
    "allowed_users": [],
    "allowed_environments": [],
    "description": "Enable dark mode UI"
  },
  "new-checkout": {
    "enabled": true,
    "type": "percentage",
    "percentage": 25,
    "allowed_tenants": [],
    "allowed_users": [],
    "allowed_environments": [],
    "description": "Gradual rollout of new checkout flow"
  }
}
```

### FlagStorageInterface

Both backends implement this contract:

```php
interface FlagStorageInterface
{
    public function get(string $name): ?FlagDefinition;
    public function all(): array;   // array<string, FlagDefinition>
    public function has(string $name): bool;
    public function set(FlagDefinition $flag): void;
    public function remove(string $name): void;
}
```

---

## FlagContext API

`FlagContext` is a readonly value object that provides the evaluation context for a flag check. It carries the current tenant, user, environment, and arbitrary attributes.

```php
use Pulsar\FeatureFlag\FlagContext;

// Manual construction
$context = new FlagContext(
    tenantId: 'acme',
    userId: 'user-42',
    environment: 'production',
    attributes: ['role' => 'admin'],
);

// Build from an HTTP request
// Reads '_tenant_id' and '_user_id' request attributes
$context = FlagContext::fromRequest($request);
```

### Properties

| Property      | Type                   | Default | Description                |
| ------------- | ---------------------- | ------- | -------------------------- |
| `tenantId`    | `?string`              | `null`  | Current tenant identifier  |
| `userId`      | `?string`              | `null`  | Current user identifier    |
| `environment` | `?string`              | `null`  | Current environment name   |
| `attributes`  | `array<string, mixed>` | `[]`    | Arbitrary extra attributes |

---

## FlagEvaluationLog

The `FlagEvaluationLog` records every flag evaluation for audit and debugging purposes. The `FeatureFlagManager` writes to this log automatically for every `evaluate()` and `isEnabled()` call.

```php
use Pulsar\FeatureFlag\FlagEvaluationLog;

$log = new FlagEvaluationLog();

// Get all recorded evaluations
$all = $log->all(); // list<FlagEvaluation>

// Get evaluations for a specific flag
$entries = $log->forFlag('new-checkout'); // list<FlagEvaluation>

// Get the count of recorded evaluations
$count = $log->count(); // int

// Clear the log
$log->clear();
```

### FlagEvaluation record

Each evaluation produces an immutable `FlagEvaluation` record:

```php
use Pulsar\FeatureFlag\FlagEvaluation;

// Properties:
$evaluation->flagName;    // string - the flag that was evaluated
$evaluation->result;      // bool - the evaluation outcome
$evaluation->reason;      // FlagEvaluationReason - why this result was produced
$evaluation->context;     // FlagContext - the context used for evaluation
$evaluation->evaluatedAt; // DateTimeImmutable - when the evaluation occurred
```

### FlagEvaluationReason

| Reason               | Description                                    |
| -------------------- | ---------------------------------------------- |
| `FlagDisabled`       | Flag exists but is disabled                    |
| `FlagEnabled`        | Boolean flag is enabled                        |
| `FlagNotFound`       | Flag does not exist in storage                 |
| `DefaultState`       | No matching rule; returned the default state   |
| `TenantMatch`        | Contextual flag matched the tenant ID          |
| `UserMatch`          | Contextual flag matched the user ID            |
| `EnvironmentMatch`   | Contextual flag matched the environment        |
| `PercentageRollout`  | Percentage flag: identifier is within rollout  |
| `PercentageExcluded` | Percentage flag: identifier is outside rollout |

---

## FeatureFlagManager API

The `FeatureFlagManager` is the primary entry point for evaluating flags.

### Constructor

```php
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagStorageInterface;

$manager = new FeatureFlagManager(
    storage: $storage,          // FlagStorageInterface
    log: $evaluationLog,        // FlagEvaluationLog
    defaultState: false,        // bool - returned when a flag is not found
);
```

### Methods

#### `isEnabled(string $flagName, ?FlagContext $context = null): bool`

Simple boolean check. Returns `true` if the flag should be active for the given context.

```php
if ($manager->isEnabled('dark-mode')) {
    // render dark mode UI
}

// With context
$context = new FlagContext(userId: 'user-42', tenantId: 'acme');
if ($manager->isEnabled('new-checkout', $context)) {
    // show new checkout flow
}
```

#### `evaluate(string $flagName, ?FlagContext $context = null): FlagEvaluation`

Returns the full evaluation record, including the result, reason, context, and timestamp. Every call is recorded in the `FlagEvaluationLog`.

```php
$evaluation = $manager->evaluate('new-checkout', $context);

$evaluation->result;       // true or false
$evaluation->reason;       // FlagEvaluationReason::PercentageRollout
$evaluation->evaluatedAt;  // DateTimeImmutable
```

#### `allFlags(): array`

Returns all registered flag definitions from the storage backend.

```php
$flags = $manager->allFlags(); // array<string, FlagDefinition>
```

---

## Complete example

```php
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Config\Environment;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagType;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;

// 1. Create storage and load flags
$storage = new InMemoryFlagStorage();

$storage->set(new FlagDefinition(
    name: 'new-dashboard',
    enabled: true,
    type: FlagType::Percentage,
    percentage: 50,
    description: '50% rollout of new dashboard',
));

$storage->set(new FlagDefinition(
    name: 'beta-reports',
    enabled: true,
    type: FlagType::Contextual,
    allowedTenants: ['acme'],
    allowedEnvironments: ['staging'],
    description: 'Beta reports for Acme on staging',
));

// 2. Create the manager
$log = new FlagEvaluationLog();
$manager = new FeatureFlagManager(
    storage: $storage,
    log: $log,
    defaultState: false,
);

// 3. Evaluate flags
$context = new FlagContext(
    tenantId: 'acme',
    userId: 'user-42',
    environment: 'production',
);

// Simple boolean check
if ($manager->isEnabled('new-dashboard', $context)) {
    // User 42 at tenant acme gets the new dashboard
    // (deterministic based on crc32('new-dashboard' . 'user-42') % 100 < 50)
}

// Full evaluation with audit record
$eval = $manager->evaluate('beta-reports', $context);
// $eval->result === true (tenant 'acme' is in allowedTenants)
// $eval->reason === FlagEvaluationReason::TenantMatch

// 4. Inspect the audit log
$allEvals = $log->all();          // All evaluations this request
$dashboardEvals = $log->forFlag('new-dashboard');
$totalEvals = $log->count();
```

---

## Error handling

| Exception              | Factory Method                                        | When Thrown                           |
| ---------------------- | ----------------------------------------------------- | ------------------------------------- |
| `FeatureFlagException` | `storageError(string $reason)`                        | File read/write failure, invalid JSON |
| `FeatureFlagException` | `invalidDefinition(string $flagName, string $reason)` | Malformed flag definition             |

---

## Environment variable overrides

| Variable                | Overrides                 | Values                |
| ----------------------- | ------------------------- | --------------------- |
| `FEATURE_FLAGS_ENABLED` | `config.features.enabled` | `'true'` or `'false'` |
