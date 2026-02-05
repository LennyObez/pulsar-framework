# Public API Reference

This document lists every class, interface, enum, and DTO that is part of Pulsar's public API surface. Types marked with the `#[Api]` attribute are covered by semantic versioning guarantees: breaking changes require a major version bump.

## API Stability System

Pulsar uses two PHP attributes to classify every type in the framework:

### `#[Api]` (Pulsar\Api\Api)

Marks a class, method, or class constant as part of the public API. Can be applied to classes, methods, and class constants. Accepts an optional `since` parameter to document when the type became public.

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Api
{
    public function __construct(
        public string $since = '',
    ) {}
}
```

### `#[Internal]` (Pulsar\Api\Internal)

Explicitly marks a class as internal. This attribute is optional -- everything without `#[Api]` is internal by default. Use this for emphasis on classes that users might mistakenly depend on. Accepts an optional `reason` parameter.

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Internal
{
    public function __construct(
        public string $reason = '',
    ) {}
}
```

### Rules

- If a type has `#[Api]`, it is public and semver-protected.
- If a type has `#[Internal]`, it is internal and may change in any minor release.
- If a type has neither attribute, it is internal by default.
- The `#[Api]` and `#[Internal]` attributes themselves are public API.

---

## Public API by Subsystem

### Api

Stability attributes for the framework itself.

| Type                  | Kind              | Description                        |
| --------------------- | ----------------- | ---------------------------------- |
| `Pulsar\Api\Api`      | Attribute (class) | Marks types as public API          |
| `Pulsar\Api\Internal` | Attribute (class) | Marks types as explicitly internal |

### Auth

Authentication, authorization, identity management, and two-factor authentication.

| Type                                              | Kind      | Description                             |
| ------------------------------------------------- | --------- | --------------------------------------- |
| `Pulsar\Auth\AuthManagerInterface`                | Interface | Core authentication manager contract    |
| `Pulsar\Auth\Identity\IdentityInterface`          | Interface | Authenticated user identity contract    |
| `Pulsar\Auth\Identity\Identity`                   | Class     | Default identity value object           |
| `Pulsar\Auth\Identity\AnonymousIdentity`          | Class     | Unauthenticated identity representation |
| `Pulsar\Auth\Identity\TwoFactorStatus`            | Enum      | Two-factor authentication status        |
| `Pulsar\Auth\Guard\GuardInterface`                | Interface | Authentication guard contract           |
| `Pulsar\Auth\Guard\TokenResolverInterface`        | Interface | Token resolution contract               |
| `Pulsar\Auth\Authorization\GateInterface`         | Interface | Authorization gate contract             |
| `Pulsar\Auth\Authorization\PolicyInterface`       | Interface | Authorization policy contract           |
| `Pulsar\Auth\Authorization\RoleRegistryInterface` | Interface | Role registry contract                  |
| `Pulsar\Auth\Authorization\Role`                  | Class     | Role value object                       |
| `Pulsar\Auth\Authorization\Permission`            | Class     | Permission value object                 |
| `Pulsar\Auth\Authorization\PolicyContext`         | Class     | Policy evaluation context               |
| `Pulsar\Auth\Password\PasswordHasherInterface`    | Interface | Password hashing contract               |
| `Pulsar\Auth\TwoFactor\TwoFactorManagerInterface` | Interface | 2FA management contract                 |
| `Pulsar\Auth\Exception\AuthenticationException`   | Exception | Authentication failure                  |
| `Pulsar\Auth\Exception\AuthorizationException`    | Exception | Authorization failure                   |

### Config

Typed configuration DTOs and loading interfaces.

| Type                                      | Kind      | Description                          |
| ----------------------------------------- | --------- | ------------------------------------ |
| `Pulsar\Config\ConfigRepository`          | Class     | Configuration value repository       |
| `Pulsar\Config\ConfigLoaderInterface`     | Interface | Configuration loading contract       |
| `Pulsar\Config\AppConfig`                 | DTO       | Application configuration            |
| `Pulsar\Config\AuthConfig`                | DTO       | Authentication configuration         |
| `Pulsar\Config\AuthGuardConfig`           | DTO       | Auth guard configuration             |
| `Pulsar\Config\AuthorizationConfig`       | DTO       | Authorization configuration          |
| `Pulsar\Config\AuditConfig`               | DTO       | Audit logging configuration          |
| `Pulsar\Config\CircuitBreakerConfig`      | DTO       | Circuit breaker configuration        |
| `Pulsar\Config\ConnectionConfig`          | DTO       | Database connection configuration    |
| `Pulsar\Config\CsrfConfig`                | DTO       | CSRF protection configuration        |
| `Pulsar\Config\DatabaseConfig`            | DTO       | Database configuration               |
| `Pulsar\Config\ErrorTrackingConfig`       | DTO       | Error tracking configuration         |
| `Pulsar\Config\FeatureFlagConfig`         | DTO       | Feature flag configuration           |
| `Pulsar\Config\HealthCheckConfig`         | DTO       | Health check configuration           |
| `Pulsar\Config\LoggingChannelConfig`      | DTO       | Logging channel configuration        |
| `Pulsar\Config\MetricsConfig`             | DTO       | Metrics configuration                |
| `Pulsar\Config\ObservabilityConfig`       | DTO       | Observability configuration          |
| `Pulsar\Config\RateLimitConfig`           | DTO       | Rate limiting configuration          |
| `Pulsar\Config\ResilienceConfig`          | DTO       | Resilience configuration             |
| `Pulsar\Config\RetryConfig`               | DTO       | Retry policy configuration           |
| `Pulsar\Config\SchedulerConfig`           | DTO       | Scheduler configuration              |
| `Pulsar\Config\SecurityConfig`            | DTO       | Security configuration               |
| `Pulsar\Config\SecurityHeadersConfig`     | DTO       | Security headers configuration       |
| `Pulsar\Config\SessionConfig`             | DTO       | Session configuration                |
| `Pulsar\Config\TenancyConfig`             | DTO       | Tenancy configuration                |
| `Pulsar\Config\TenantDatabaseConfig`      | DTO       | Tenant database configuration        |
| `Pulsar\Config\TracingConfig`             | DTO       | Tracing configuration                |
| `Pulsar\Config\TwoFactorConfig`           | DTO       | Two-factor auth configuration        |
| `Pulsar\Config\Environment`               | DTO       | Environment configuration            |
| `Pulsar\Config\EnvironmentMode`           | Enum      | Environment modes (production, etc.) |
| `Pulsar\Config\Exception\ConfigException` | Exception | Configuration error                  |

### Console

CLI application contracts and value types.

| Type                                                | Kind      | Description                  |
| --------------------------------------------------- | --------- | ---------------------------- |
| `Pulsar\Console\CommandInterface`                   | Interface | Console command contract     |
| `Pulsar\Console\InputInterface`                     | Interface | Command input contract       |
| `Pulsar\Console\OutputInterface`                    | Interface | Command output contract      |
| `Pulsar\Console\ExitCode`                           | Enum      | Command exit codes (0, 1, 2) |
| `Pulsar\Console\Verbosity`                          | Enum      | Output verbosity levels      |
| `Pulsar\Console\Exception\ConsoleException`         | Exception | Console error                |
| `Pulsar\Console\Exception\CommandNotFoundException` | Exception | Command not found error      |

### Container

PSR-11 compatible dependency injection container.

| Type                                            | Kind      | Description                                   |
| ----------------------------------------------- | --------- | --------------------------------------------- |
| `Pulsar\Container\ContainerInterface`           | Interface | PSR-11 DI container contract                  |
| `Pulsar\Container\BindingType`                  | Enum      | Binding types (singleton, factory, transient) |
| `Pulsar\Container\Exception\ContainerException` | Exception | Container error                               |
| `Pulsar\Container\Exception\NotFoundException`  | Exception | Service not found error                       |

### Database

Database abstraction, connections, and migrations.

| Type                                           | Kind      | Description                   |
| ---------------------------------------------- | --------- | ----------------------------- |
| `Pulsar\Database\ConnectionInterface`          | Interface | Database connection contract  |
| `Pulsar\Database\ConnectionManagerInterface`   | Interface | Connection manager contract   |
| `Pulsar\Database\Result`                       | Class     | Query result value object     |
| `Pulsar\Database\Row`                          | Class     | Result row value object       |
| `Pulsar\Database\Driver`                       | Enum      | Database driver types         |
| `Pulsar\Database\FetchMode`                    | Enum      | Fetch mode options            |
| `Pulsar\Database\Migration\MigrationInterface` | Interface | Migration contract            |
| `Pulsar\Database\Migration\MigrationRecord`    | Class     | Applied migration record      |
| `Pulsar\Database\Migration\MigrationFile`      | Class     | Migration file descriptor     |
| `Pulsar\Database\Migration\MigrationDirection` | Enum      | Migration direction (up/down) |
| `Pulsar\Database\Exception\DatabaseException`  | Exception | Database error                |

### ErrorHandling

Exception rendering and HTTP exception types.

| Type                                                    | Kind      | Description                 |
| ------------------------------------------------------- | --------- | --------------------------- |
| `Pulsar\ErrorHandling\HttpExceptionInterface`           | Interface | HTTP exception contract     |
| `Pulsar\ErrorHandling\HttpException`                    | Class     | HTTP exception base class   |
| `Pulsar\ErrorHandling\ExceptionRendererInterface`       | Interface | Exception renderer contract |
| `Pulsar\ErrorHandling\Exception\ErrorHandlingException` | Exception | Error handling system error |

### Extensibility

Extension system interfaces, manifests, and lifecycle.

| Type                                                 | Kind      | Description                        |
| ---------------------------------------------------- | --------- | ---------------------------------- |
| `Pulsar\Extensibility\ExtensionInterface`            | Interface | Extension contract                 |
| `Pulsar\Extensibility\ServiceProviderInterface`      | Interface | Service provider contract          |
| `Pulsar\Extensibility\ExtensionManifest`             | DTO       | Extension manifest (readonly)      |
| `Pulsar\Extensibility\ExtensionLifecycle`            | Enum      | Extension lifecycle states         |
| `Pulsar\Extensibility\Manifest\ProvidesConfig`       | DTO       | Extension capabilities declaration |
| `Pulsar\Extensibility\Manifest\RequiresConfig`       | DTO       | Extension dependency declaration   |
| `Pulsar\Extensibility\Manifest\PulsarVersionConfig`  | DTO       | Framework version constraints      |
| `Pulsar\Extensibility\Exception\ExtensionException`  | Exception | Extension error                    |
| `Pulsar\Extensibility\Exception\ManifestException`   | Exception | Manifest parsing/validation error  |
| `Pulsar\Extensibility\Exception\DependencyException` | Exception | Extension dependency error         |

### FeatureFlag

Feature flag evaluation and storage.

| Type                                                | Kind      | Description                            |
| --------------------------------------------------- | --------- | -------------------------------------- |
| `Pulsar\FeatureFlag\FeatureFlagManagerInterface`    | Interface | Feature flag manager contract          |
| `Pulsar\FeatureFlag\FlagStorageInterface`           | Interface | Flag storage backend contract          |
| `Pulsar\FeatureFlag\FlagDefinition`                 | Class     | Feature flag definition                |
| `Pulsar\FeatureFlag\FlagEvaluation`                 | Class     | Flag evaluation result                 |
| `Pulsar\FeatureFlag\FlagContext`                    | Class     | Evaluation context                     |
| `Pulsar\FeatureFlag\FlagType`                       | Enum      | Flag types (boolean, percentage, etc.) |
| `Pulsar\FeatureFlag\FlagStorageDriver`              | Enum      | Storage driver types                   |
| `Pulsar\FeatureFlag\FlagEvaluationReason`           | Enum      | Evaluation reason codes                |
| `Pulsar\FeatureFlag\Exception\FeatureFlagException` | Exception | Feature flag error                     |

### Http

HTTP request/response value objects, middleware, validation, and rate limiting.

| Type                                         | Kind      | Description                          |
| -------------------------------------------- | --------- | ------------------------------------ |
| `Pulsar\Http\Request`                        | Class     | Immutable HTTP request value object  |
| `Pulsar\Http\Response`                       | Class     | Immutable HTTP response value object |
| `Pulsar\Http\HeaderBag`                      | Class     | HTTP header collection               |
| `Pulsar\Http\ResponseEmitter`                | Class     | Response output emitter              |
| `Pulsar\Http\Method`                         | Enum      | HTTP methods (GET, POST, PUT, etc.)  |
| `Pulsar\Http\ResponseStatus`                 | Enum      | HTTP status codes                    |
| `Pulsar\Http\Middleware\MiddlewareInterface` | Interface | Middleware contract                  |
| `Pulsar\Http\Validation\Validator`           | Class     | Request validator                    |
| `Pulsar\Http\Validation\ValidationResult`    | Class     | Validation result value object       |
| `Pulsar\Http\Validation\ValidationException` | Exception | Validation failure                   |
| `Pulsar\Http\Validation\Violation`           | Class     | Single validation violation          |
| `Pulsar\Http\Validation\RuleInterface`       | Interface | Validation rule contract             |
| `Pulsar\Http\Validation\Rule\Required`       | Class     | Required field rule                  |
| `Pulsar\Http\Validation\Rule\Email`          | Class     | Email validation rule                |
| `Pulsar\Http\Validation\Rule\Min`            | Class     | Minimum value rule                   |
| `Pulsar\Http\Validation\Rule\Max`            | Class     | Maximum value rule                   |
| `Pulsar\Http\Validation\Rule\MinLength`      | Class     | Minimum string length rule           |
| `Pulsar\Http\Validation\Rule\MaxLength`      | Class     | Maximum string length rule           |
| `Pulsar\Http\Validation\Rule\Between`        | Class     | Numeric range rule                   |
| `Pulsar\Http\Validation\Rule\In`             | Class     | Allowed values rule                  |
| `Pulsar\Http\Validation\Rule\Regex`          | Class     | Regular expression rule              |
| `Pulsar\Http\Validation\Rule\IntegerType`    | Class     | Integer type rule                    |
| `Pulsar\Http\Validation\Rule\StringType`     | Class     | String type rule                     |
| `Pulsar\Http\RateLimit\RateLimitResult`      | Class     | Rate limit check result              |

### Observability

Structured logging, distributed tracing, metrics collection, and error tracking.

| Type                                                                  | Kind      | Description                         |
| --------------------------------------------------------------------- | --------- | ----------------------------------- |
| `Pulsar\Observability\Log\LogSinkInterface`                           | Interface | Log output sink contract            |
| `Pulsar\Observability\Log\LogEntry`                                   | Class     | Structured log entry                |
| `Pulsar\Observability\Log\LogLevel`                                   | Enum      | Log severity levels                 |
| `Pulsar\Observability\Log\Exception\LogException`                     | Exception | Logging error                       |
| `Pulsar\Observability\Tracing\Span`                                   | Class     | Trace span value object             |
| `Pulsar\Observability\Tracing\TraceContext`                           | Class     | Trace context propagation           |
| `Pulsar\Observability\Tracing\SpanProcessorInterface`                 | Interface | Span processing contract            |
| `Pulsar\Observability\Tracing\SpanStatus`                             | Enum      | Span status codes                   |
| `Pulsar\Observability\Tracing\Exception\TracingException`             | Exception | Tracing error                       |
| `Pulsar\Observability\Metrics\MetricRegistry`                         | Class     | Metric registration and collection  |
| `Pulsar\Observability\Metrics\MetricType`                             | Enum      | Metric types (counter, gauge, etc.) |
| `Pulsar\Observability\ErrorTracking\ErrorFingerprint`                 | Class     | Error grouping fingerprint          |
| `Pulsar\Observability\ErrorTracking\Exception\ErrorTrackingException` | Exception | Error tracking error                |

### Resilience

Health checks, circuit breaker, retry policies, and self-healing.

| Type                                                 | Kind      | Description                                  |
| ---------------------------------------------------- | --------- | -------------------------------------------- |
| `Pulsar\Resilience\HealthCheck\HealthCheckInterface` | Interface | Health check contract                        |
| `Pulsar\Resilience\HealthCheck\HealthCheckResult`    | Class     | Individual check result                      |
| `Pulsar\Resilience\HealthCheck\HealthReport`         | Class     | Aggregate health report                      |
| `Pulsar\Resilience\HealthCheck\HealthStatus`         | Enum      | Health status (Healthy, Degraded, Unhealthy) |
| `Pulsar\Resilience\CircuitBreakerState`              | Enum      | Circuit breaker states                       |
| `Pulsar\Resilience\RetryPolicy`                      | Class     | Retry policy configuration                   |
| `Pulsar\Resilience\RetryResult`                      | Class     | Retry attempt result                         |
| `Pulsar\Resilience\Repair\RepairJobInterface`        | Interface | Self-healing repair job contract             |
| `Pulsar\Resilience\Repair\RepairDiagnosis`           | Class     | Repair diagnosis result                      |
| `Pulsar\Resilience\Repair\RepairResult`              | Class     | Repair execution result                      |
| `Pulsar\Resilience\Exception\ResilienceException`    | Exception | Resilience error                             |

### Routing

HTTP router, route definitions, groups, and matching.

| Type                              | Kind      | Description                                      |
| --------------------------------- | --------- | ------------------------------------------------ |
| `Pulsar\Routing\Router`           | Class     | HTTP router with static and parameterized routes |
| `Pulsar\Routing\Route`            | Class     | Route definition value object                    |
| `Pulsar\Routing\RouteGroup`       | Class     | Route group with prefix and middleware           |
| `Pulsar\Routing\MatchedRoute`     | Class     | Matched route with extracted parameters          |
| `Pulsar\Routing\RoutingException` | Exception | Routing error                                    |

### Scheduler

Job scheduling with cron expressions.

| Type                                            | Kind      | Description                     |
| ----------------------------------------------- | --------- | ------------------------------- |
| `Pulsar\Scheduler\JobInterface`                 | Interface | Scheduled job contract          |
| `Pulsar\Scheduler\Schedule`                     | Class     | Job schedule (cron expression)  |
| `Pulsar\Scheduler\JobContext`                   | Class     | Job execution context           |
| `Pulsar\Scheduler\JobResult`                    | Class     | Job execution result            |
| `Pulsar\Scheduler\JobEvent`                     | Class     | Job lifecycle event             |
| `Pulsar\Scheduler\JobStatus`                    | Enum      | Job execution status            |
| `Pulsar\Scheduler\SchedulerTickResult`          | Class     | Scheduler tick aggregate result |
| `Pulsar\Scheduler\CronFields`                   | Class     | Cron expression field parser    |
| `Pulsar\Scheduler\Exception\SchedulerException` | Exception | Scheduler error                 |

### Security

Session management, CSRF protection, and audit logging.

| Type                                             | Kind      | Description                    |
| ------------------------------------------------ | --------- | ------------------------------ |
| `Pulsar\Security\Session\SessionInterface`       | Interface | Session management contract    |
| `Pulsar\Security\Csrf\CsrfTokenManagerInterface` | Interface | CSRF token management contract |
| `Pulsar\Security\Audit\AuditLogger`              | Class     | Audit event logger             |
| `Pulsar\Security\Audit\AuditEntry`               | Class     | Audit log entry value object   |
| `Pulsar\Security\Audit\AuditSinkInterface`       | Interface | Audit output sink contract     |
| `Pulsar\Security\Audit\AuditEvent`               | Enum      | Audit event types              |
| `Pulsar\Security\Audit\AuditOutcome`             | Enum      | Audit event outcomes           |
| `Pulsar\Security\Exception\SecurityException`    | Exception | Security error                 |

### Tenancy

Multi-tenant architecture support.

| Type                                        | Kind      | Description                          |
| ------------------------------------------- | --------- | ------------------------------------ |
| `Pulsar\Tenancy\TenantResolverInterface`    | Interface | Tenant resolution contract           |
| `Pulsar\Tenancy\Tenant`                     | Class     | Tenant value object                  |
| `Pulsar\Tenancy\TenantResolverStrategy`     | Enum      | Tenant resolution strategies         |
| `Pulsar\Tenancy\TenantDatabaseStrategy`     | Enum      | Tenant database isolation strategies |
| `Pulsar\Tenancy\Exception\TenancyException` | Exception | Tenancy error                        |

---

## Internal Classes (10)

These classes are explicitly marked `#[Internal]` and are not covered by semver guarantees. Do not depend on them in application code.

| Type                                        | Reason                                          |
| ------------------------------------------- | ----------------------------------------------- |
| `Pulsar\Core\Kernel`                        | Framework lifecycle orchestrator                |
| `Pulsar\Core\Version`                       | Version constants (may change between releases) |
| `Pulsar\Config\ConfigManager`               | Config loading implementation detail            |
| `Pulsar\Config\ConfigOverrides`             | Config override implementation detail           |
| `Pulsar\Console\Application`                | CLI application orchestrator                    |
| `Pulsar\Extensibility\ExtensionRegistry`    | Extension storage implementation                |
| `Pulsar\Extensibility\ExtensionBootstrap`   | Extension boot pipeline implementation          |
| `Pulsar\Extensibility\ExtensionLoader`      | Extension discovery implementation              |
| `Pulsar\Http\Middleware\MiddlewarePipeline` | Middleware execution implementation             |
| `Pulsar\Http\Middleware\MiddlewareRegistry` | Middleware storage implementation               |

## Snapshot Workflow

The public API surface is tracked by a committed JSON snapshot at `tools/api/public-api.snapshot.json`. A PHPUnit test (`PublicApiSnapshotTest`) regenerates the snapshot in-memory and compares it against the committed file — any drift fails the test suite.

### Adding new public API

1. Add the `#[Api]` attribute to the new class, method, or constant.
2. Regenerate the snapshot:
   ```bash
   composer api:snapshot
   ```
3. Review the diff in the snapshot file to confirm only the expected additions appear.
4. Commit the updated snapshot alongside the source changes.

### Regenerating after changes

Any time you add, remove, or rename an `#[Api]` or `#[Internal]` annotated type, regenerate:

```bash
composer api:snapshot
```

### CI enforcement

The snapshot test runs as part of `composer test`. If the snapshot is stale, the test fails with instructions to regenerate. This ensures every PR that touches the public API surface includes an updated snapshot for review.

## Statistics

- **Total public API types**: 170 (interfaces, classes, enums, DTOs, exceptions)
- **Total internal types**: 10 (explicitly marked)
- **Subsystems**: 16
- **Convention**: Everything without `#[Api]` is internal by default
