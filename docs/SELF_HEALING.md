# Self-Healing and Resilience

## Overview

Pulsar provides a comprehensive resilience layer for building fault-tolerant, mission-critical applications. The resilience system covers four areas:

1. **Retry policies** -- Automatic retries with exponential backoff and jitter for transient failures.
2. **Circuit breakers** -- Prevent cascading failures by short-circuiting calls to degraded dependencies.
3. **Health checks** -- Continuous monitoring of system component availability and performance.
4. **Repair jobs** -- Automated diagnosis and self-healing for known failure modes.

These patterns are essential in regulated domains (banking, healthcare, legal) where system availability and auditability are non-negotiable.

### Key Components

| Class                    | Namespace                       | Purpose                                     |
| ------------------------ | ------------------------------- | ------------------------------------------- |
| `RetryPolicy`            | `Pulsar\Resilience`             | Retry with exponential backoff and jitter   |
| `RetryResult`            | `Pulsar\Resilience`             | Result of a retry execution                 |
| `CircuitBreaker`         | `Pulsar\Resilience`             | Circuit breaker state machine               |
| `CircuitBreakerState`    | `Pulsar\Resilience`             | Enum: `Closed`, `Open`, `HalfOpen`          |
| `CircuitBreakerRegistry` | `Pulsar\Resilience`             | Named circuit breaker registry              |
| `HealthCheckInterface`   | `Pulsar\Resilience\HealthCheck` | Contract for health checks                  |
| `HealthCheckResult`      | `Pulsar\Resilience\HealthCheck` | Single health check result                  |
| `HealthStatus`           | `Pulsar\Resilience\HealthCheck` | Enum: `Healthy`, `Degraded`, `Unhealthy`    |
| `HealthReport`           | `Pulsar\Resilience\HealthCheck` | Aggregate report from all checks            |
| `HealthCheckRunner`      | `Pulsar\Resilience\HealthCheck` | Orchestrates health check execution         |
| `DatabaseHealthCheck`    | `Pulsar\Resilience\HealthCheck` | Built-in database connectivity check        |
| `RepairJobInterface`     | `Pulsar\Resilience\Repair`      | Contract for self-healing repair jobs       |
| `RepairDiagnosis`        | `Pulsar\Resilience\Repair`      | Diagnosis result from a repair job          |
| `RepairResult`           | `Pulsar\Resilience\Repair`      | Result of a repair action                   |
| `RepairRunner`           | `Pulsar\Resilience\Repair`      | Orchestrates repair diagnosis and execution |
| `ResilienceConfig`       | `Pulsar\Config`                 | Top-level resilience configuration DTO      |
| `RetryConfig`            | `Pulsar\Config`                 | Retry policy configuration DTO              |
| `CircuitBreakerConfig`   | `Pulsar\Config`                 | Circuit breaker configuration DTO           |
| `HealthCheckConfig`      | `Pulsar\Config`                 | Health check configuration DTO              |
| `ResilienceException`    | `Pulsar\Resilience\Exception`   | Exception type for resilience errors        |
| `HealthCheckCommand`     | `Pulsar\Console\Command`        | CLI command: `health:check`                 |
| `RepairCommand`          | `Pulsar\Console\Command`        | CLI command: `health:repair`                |

---

## Configuration Reference

All resilience settings live in `config/resilience.php`. The `RESILIENCE_ENABLED` environment variable overrides the `enabled` key.

```php
// config/resilience.php
return [
    'enabled' => false,

    'retry' => [
        'max_attempts' => 3,        // Total attempts (including first try)
        'base_delay_ms' => 100,     // Initial delay in milliseconds
        'max_delay_ms' => 5000,     // Maximum delay cap in milliseconds
        'multiplier' => 2.0,        // Exponential backoff multiplier
        'jitter' => true,           // Add random jitter to prevent thundering herd
    ],

    'circuit_breaker' => [
        'failure_threshold' => 5,        // Failures before opening
        'success_threshold' => 2,        // Successes in half-open to close
        'open_timeout_seconds' => 30,    // Seconds before transitioning to half-open
        'sample_window_seconds' => 60,   // Window for counting failures
    ],

    'health_check' => [
        'interval_seconds' => 30,   // How often checks run
        'timeout_seconds' => 5,     // Timeout per individual check
    ],
];
```

### Configuration DTOs

The raw config array is parsed into a hierarchy of typed DTOs:

```php
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\HealthCheckConfig;

// Top-level DTO
readonly class ResilienceConfig
{
    public function __construct(
        public bool $enabled = false,
        public RetryConfig $retry = new RetryConfig(),
        public CircuitBreakerConfig $circuitBreaker = new CircuitBreakerConfig(),
        public HealthCheckConfig $healthCheck = new HealthCheckConfig(),
    ) {}
}

// Retry sub-config
readonly class RetryConfig
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 100,
        public int $maxDelayMs = 5000,
        public float $multiplier = 2.0,
        public bool $jitter = true,
    ) {}
}

// Circuit breaker sub-config
readonly class CircuitBreakerConfig
{
    public function __construct(
        public int $failureThreshold = 5,
        public int $successThreshold = 2,
        public int $openTimeoutSeconds = 30,
        public int $sampleWindowSeconds = 60,
    ) {}
}

// Health check sub-config
readonly class HealthCheckConfig
{
    public function __construct(
        public int $intervalSeconds = 30,
        public int $timeoutSeconds = 5,
    ) {}
}
```

---

## Retry Policies

### Overview

The `RetryPolicy` implements automatic retries with exponential backoff and optional jitter. It wraps a closure and re-executes it on failure, with increasing delays between attempts.

### Backoff Algorithm

The delay for attempt `n` is calculated as:

```
delay = baseDelayMs * multiplier^(n - 1)
delay = min(delay, maxDelayMs)
```

When jitter is enabled, a random offset in the range `[-delay*0.5, +delay*0.5]` is applied. The final delay is clamped to a minimum of 1ms.

**Example with defaults** (`baseDelayMs=100`, `multiplier=2.0`, `maxDelayMs=5000`, `jitter=true`):

| Attempt               | Base Delay            | With Jitter (approx.) |
| --------------------- | --------------------- | --------------------- |
| 1                     | First try -- no delay | --                    |
| 2                     | 100ms                 | 50-150ms              |
| 3                     | 200ms                 | 100-300ms             |
| 4 (if max_attempts=4) | 400ms                 | 200-600ms             |

### Creating a RetryPolicy

```php
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Config\RetryConfig;

// From configuration DTO
$config = new RetryConfig(
    maxAttempts: 5,
    baseDelayMs: 200,
    maxDelayMs: 10000,
    multiplier: 2.0,
    jitter: true,
);
$policy = RetryPolicy::fromConfig($config);

// Direct construction
$policy = new RetryPolicy(
    maxAttempts: 3,
    baseDelayMs: 100,
    maxDelayMs: 5000,
    multiplier: 2.0,
    jitter: true,
);
```

### Executing with Retry

```php
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Resilience\RetryResult;

$policy = RetryPolicy::fromConfig($retryConfig);

$result = $policy->execute(
    operation: function (): array {
        // This may throw on transient failure
        return $httpClient->get('https://api.external.com/data');
    },
    logger: $logger,  // optional - logs each retry attempt
);

if ($result->succeeded) {
    $data = $result->result;      // The return value from the closure
    $attempts = $result->attempts; // How many attempts were needed
} else {
    $exception = $result->lastException;  // The last exception thrown
    $attempts = $result->attempts;         // Total attempts made
}
```

### RetryResult

```php
readonly class RetryResult
{
    public bool $succeeded;          // Whether the operation eventually succeeded
    public int $attempts;            // Total number of attempts
    public mixed $result;            // Return value (null if failed)
    public ?Throwable $lastException; // Last exception (null if succeeded)
    public array $attemptDelays;     // list<int> -- delay before each retry in ms
}
```

### Logging

When a logger is provided to `execute()`:

- **WARNING:** Logged after each failed attempt with the attempt number, max attempts, error message, and retry delay.
- **INFO:** Logged when a retry succeeds on attempt > 1.
- **ERROR:** Logged when all attempts are exhausted.

### Inspecting Delay Calculation

```php
// Calculate the delay for a specific attempt (useful for testing)
$delay = $policy->calculateDelay(attempt: 3); // int milliseconds
```

---

## Circuit Breakers

### Overview

The circuit breaker pattern prevents cascading failures by tracking the success/failure rate of calls to a dependency. When failures exceed a threshold, the circuit "opens" and immediately rejects subsequent calls, giving the dependency time to recover.

### State Machine

The circuit breaker has three states:

```
     ┌──────────────────────────────────────────┐
     │                                          │
     v                                          │
  CLOSED ─── failure_threshold reached ──> OPEN │
     ^                                     │    │
     │                                     │    │
     │         open_timeout_seconds        │    │
     │         elapsed                     v    │
     │                                HALF_OPEN │
     │                                     │    │
     └── success_threshold reached ────────┘    │
                                                │
         failure in half-open ──────────────────┘
```

| State        | Behavior                                                                                                                                                                            |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Closed**   | Normal operation. Failures are counted. When `failureCount >= failureThreshold`, transitions to Open.                                                                               |
| **Open**     | All calls are rejected immediately with `ResilienceException::circuitOpen()`. After `openTimeoutSeconds` elapse, transitions to HalfOpen.                                           |
| **HalfOpen** | A limited number of probe requests are allowed through. If `successThreshold` consecutive successes occur, transitions to Closed. Any failure immediately transitions back to Open. |

### CircuitBreakerState Enum

```php
enum CircuitBreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
```

### Creating a CircuitBreaker

```php
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Config\CircuitBreakerConfig;

// From configuration
$config = new CircuitBreakerConfig(
    failureThreshold: 5,
    successThreshold: 2,
    openTimeoutSeconds: 30,
    sampleWindowSeconds: 60,
);
$breaker = CircuitBreaker::fromConfig('payment-gateway', $config);

// Direct construction
$breaker = new CircuitBreaker(
    name: 'payment-gateway',
    failureThreshold: 5,
    successThreshold: 2,
    openTimeoutSeconds: 30,
);
```

### Executing Through a Circuit Breaker

```php
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\Exception\ResilienceException;

try {
    $result = $breaker->execute(function () use ($gateway) {
        return $gateway->charge($amount);
    });
    // $result is the return value from the closure
} catch (ResilienceException $e) {
    // Circuit is open - calls are being rejected
    // Handle gracefully: show cached data, queue for retry, etc.
} catch (\Throwable $e) {
    // The operation itself failed (and the failure was recorded)
    // The circuit may have transitioned to open
}
```

### Manual State Management

```php
// Check current state
$state = $breaker->state();        // CircuitBreakerState
$available = $breaker->isAvailable(); // true if Closed or HalfOpen

// Record results manually (without execute())
$breaker->recordSuccess();
$breaker->recordFailure();

// Inspect counters
$failures = $breaker->failureCount();
$successes = $breaker->successCount();

// Reset to closed state
$breaker->reset();

// Get the breaker name
$name = $breaker->name();
```

### CircuitBreakerRegistry

The registry provides create-or-return semantics, ensuring a single circuit breaker instance per name throughout the application lifecycle.

```php
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Config\CircuitBreakerConfig;

$registry = new CircuitBreakerRegistry(
    defaultConfig: new CircuitBreakerConfig(
        failureThreshold: 5,
        successThreshold: 2,
        openTimeoutSeconds: 30,
    ),
);

// Get or create a breaker (uses default config for new breakers)
$breaker = $registry->get('payment-gateway');
$breaker = $registry->get('email-service');

// Subsequent calls return the same instance
$same = $registry->get('payment-gateway'); // same object as above

// Check existence
$exists = $registry->has('payment-gateway'); // bool

// Get all registered breakers
$all = $registry->all(); // array<string, CircuitBreaker>

// Reset a specific breaker
$registry->reset('payment-gateway');

// Reset all breakers
$registry->resetAll();
```

### Combining Retry + Circuit Breaker

A common pattern is to wrap a retry policy inside a circuit breaker:

```php
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\RetryPolicy;

$breaker = $registry->get('external-api');
$retry = RetryPolicy::fromConfig($retryConfig);

try {
    $result = $breaker->execute(function () use ($retry, $apiClient, $logger) {
        $retryResult = $retry->execute(
            fn() => $apiClient->fetchData(),
            $logger,
        );

        if (!$retryResult->succeeded) {
            throw $retryResult->lastException;
        }

        return $retryResult->result;
    });
} catch (ResilienceException $e) {
    // Circuit is open
} catch (\Throwable $e) {
    // All retries exhausted; circuit breaker recorded the failure
}
```

---

## Health Checks

### Overview

Health checks verify that system components (databases, caches, external services) are operational. Each check produces a result with a status, message, and response time.

### HealthCheckInterface

```php
interface HealthCheckInterface
{
    public function getName(): string;
    public function check(): HealthCheckResult;
}
```

### HealthStatus Enum

```php
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
```

### HealthCheckResult

An immutable result from a single health check:

```php
readonly class HealthCheckResult
{
    public string $name;
    public HealthStatus $status;
    public string $message;
    public float $responseTimeMs;
    public DateTimeImmutable $checkedAt;

    // Factory methods
    public static function healthy(string $name, string $message = 'OK', float $responseTimeMs = 0.0): self;
    public static function degraded(string $name, string $message, float $responseTimeMs = 0.0): self;
    public static function unhealthy(string $name, string $message, float $responseTimeMs = 0.0): self;
}
```

### Built-in: DatabaseHealthCheck

Pulsar ships a `DatabaseHealthCheck` that verifies database connectivity by executing `SELECT 1` and measuring response time.

```php
use Pulsar\Resilience\HealthCheck\DatabaseHealthCheck;

$check = new DatabaseHealthCheck(
    connectionManager: $connectionManager,
    connectionName: null,  // null = default connection
);

$result = $check->check();
// HealthCheckResult with:
//   status: Healthy (< 1000ms), Degraded (> 1000ms), or Unhealthy (exception)
//   message: "Database responded in 2.3ms" or "Database check failed: ..."
//   responseTimeMs: actual response time
```

**Thresholds:**

- Response time <= 1000ms: `Healthy`
- Response time > 1000ms: `Degraded` (database is slow)
- Exception thrown: `Unhealthy`

### Writing a Custom Health Check

```php
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;

final readonly class RedisHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private RedisClient $redis,
    ) {}

    public function getName(): string
    {
        return 'redis';
    }

    public function check(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            $pong = $this->redis->ping();
            $elapsed = (microtime(true) - $start) * 1000;

            if ($pong !== 'PONG') {
                return HealthCheckResult::degraded(
                    $this->getName(),
                    'Redis responded but ping returned unexpected value',
                    $elapsed,
                );
            }

            return HealthCheckResult::healthy(
                $this->getName(),
                sprintf('Redis responded in %.1fms', $elapsed),
                $elapsed,
            );
        } catch (\Throwable $e) {
            $elapsed = (microtime(true) - $start) * 1000;

            return HealthCheckResult::unhealthy(
                $this->getName(),
                sprintf('Redis check failed: %s', $e->getMessage()),
                $elapsed,
            );
        }
    }
}
```

### HealthCheckRunner

Orchestrates the execution of all registered health checks and produces an aggregate report.

```php
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;

$runner = new HealthCheckRunner();

// Register checks
$runner->register(new DatabaseHealthCheck($connectionManager));
$runner->register(new RedisHealthCheck($redis));

// Run all checks
$report = $runner->runAll();
// HealthReport with:
//   overallStatus: worst status across all checks
//   results: list<HealthCheckResult>
//   generatedAt: DateTimeImmutable

if ($report->isHealthy()) {
    // All systems operational
}

// Run a single check by name
$dbResult = $runner->run('database');

// Get all registered check names
$names = $runner->names(); // list<string>
```

### HealthReport

```php
readonly class HealthReport
{
    public HealthStatus $overallStatus;  // Worst status across all results
    public array $results;               // list<HealthCheckResult>
    public DateTimeImmutable $generatedAt;

    public function isHealthy(): bool;   // true if overallStatus is Healthy
}
```

The `overallStatus` is determined by the worst individual result:

- If any check is `Unhealthy`, overall is `Unhealthy`.
- If any check is `Degraded` (and none is `Unhealthy`), overall is `Degraded`.
- If all checks are `Healthy`, overall is `Healthy`.

---

## Repair System

### Overview

The repair system extends health checks with automated diagnosis and remediation. Each repair job can diagnose whether a problem exists and attempt to fix it. This enables self-healing behavior for known failure modes such as stale caches, orphaned locks, or misconfigured resources.

### RepairJobInterface

```php
interface RepairJobInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function diagnose(): RepairDiagnosis;
    public function repair(): RepairResult;
}
```

### RepairDiagnosis

```php
readonly class RepairDiagnosis
{
    public string $repairJobName;
    public bool $needsRepair;       // Whether this job detected an issue
    public string $description;     // Human-readable description of the diagnosis
    public array $findings;         // list<string> -- specific findings
}
```

### RepairResult

```php
readonly class RepairResult
{
    public string $repairJobName;
    public bool $success;           // Whether the repair succeeded
    public string $description;     // Human-readable description
    public array $actionsPerformed; // list<string> -- what was done
    public ?Throwable $exception;   // Exception if repair failed
}
```

### Writing a Repair Job

```php
use Pulsar\Resilience\Repair\RepairJobInterface;
use Pulsar\Resilience\Repair\RepairDiagnosis;
use Pulsar\Resilience\Repair\RepairResult;

final class StaleLockRepairJob implements RepairJobInterface
{
    public function __construct(
        private readonly LockManager $locks,
    ) {}

    public function getName(): string
    {
        return 'stale-locks';
    }

    public function getDescription(): string
    {
        return 'Detect and remove stale advisory locks older than 1 hour';
    }

    public function diagnose(): RepairDiagnosis
    {
        $staleLocks = $this->locks->findStale(maxAgeSeconds: 3600);

        return new RepairDiagnosis(
            repairJobName: $this->getName(),
            needsRepair: $staleLocks !== [],
            description: count($staleLocks) > 0
                ? sprintf('Found %d stale lock(s)', count($staleLocks))
                : 'No stale locks found',
            findings: array_map(
                fn($lock) => sprintf('Lock "%s" held since %s', $lock->name, $lock->acquiredAt->format('c')),
                $staleLocks,
            ),
        );
    }

    public function repair(): RepairResult
    {
        $staleLocks = $this->locks->findStale(maxAgeSeconds: 3600);
        $actions = [];

        foreach ($staleLocks as $lock) {
            $this->locks->forceRelease($lock->name);
            $actions[] = sprintf('Released lock "%s"', $lock->name);
        }

        return new RepairResult(
            repairJobName: $this->getName(),
            success: true,
            description: sprintf('Released %d stale lock(s)', count($actions)),
            actionsPerformed: $actions,
        );
    }
}
```

### RepairRunner

Orchestrates diagnosis and repair across all registered repair jobs.

```php
use Pulsar\Resilience\Repair\RepairRunner;

$runner = new RepairRunner();

// Register repair jobs
$runner->register(new StaleLockRepairJob($locks));
$runner->register(new OrphanedTempFileRepairJob($filesystem));

// Diagnose all jobs (does not perform repairs)
$diagnoses = $runner->diagnoseAll(); // list<RepairDiagnosis>
foreach ($diagnoses as $d) {
    echo "{$d->repairJobName}: " . ($d->needsRepair ? 'NEEDS REPAIR' : 'OK') . "\n";
}

// Run repairs on all jobs that need repair
// (diagnoses first, then repairs only those that report needsRepair = true)
$results = $runner->repairAll(); // list<RepairResult>
foreach ($results as $r) {
    echo "{$r->repairJobName}: " . ($r->success ? 'FIXED' : 'FAILED') . "\n";
}

// Run a specific repair job by name
$result = $runner->repair('stale-locks');

// Get all registered repair job names
$names = $runner->names(); // list<string>
```

If a repair job throws an exception during `repair()`, the `RepairRunner` catches it and returns a failed `RepairResult` with the exception attached.

---

## Console Commands

### `health:check`

Runs all registered health checks and displays results.

```
php bin/pulsar health:check
```

**Output example:**

```
  [OK] database - Database responded in 2.3ms (2.3ms)
  [WARN] redis - Redis responded in 1203.5ms (slow) (1203.5ms)
  [FAIL] external-api - Connection refused (5001.2ms)

Overall status: unhealthy
```

**Status indicators:**

- `[OK]` -- Healthy
- `[WARN]` -- Degraded
- `[FAIL]` -- Unhealthy

**Exit codes:**

- `0` -- All checks passed (overall status is Healthy).
- `1` -- At least one check is Degraded or Unhealthy.

### `health:repair`

Diagnoses all registered repair jobs and runs repairs where needed.

```
php bin/pulsar health:repair
```

**Output example:**

```
  [OK] orphaned-temp-files - No orphaned files found
  [NEEDS REPAIR] stale-locks - Found 3 stale lock(s)

Running 1 repair(s)...

  [FIXED] stale-locks - Released 3 stale lock(s)
    -> Released lock "job_import_data"
    -> Released lock "job_sync_users"
    -> Released lock "job_generate_report"

All 1 repair(s) completed successfully.
```

**Exit codes:**

- `0` -- No repairs needed, or all repairs succeeded.
- `1` -- One or more repairs failed.

---

## Observability Integration

The resilience system integrates with Pulsar's observability layer to emit structured data for monitoring and compliance.

### Metrics Emitted

| Metric                        | Type      | Labels                             | Description                            |
| ----------------------------- | --------- | ---------------------------------- | -------------------------------------- |
| Retry attempt count           | Counter   | operation                          | Number of retry attempts per operation |
| Circuit breaker state changes | Counter   | breaker_name, from_state, to_state | State transition events                |
| Health check results          | Gauge     | check_name, status                 | Latest status per check                |
| Health check response time    | Histogram | check_name                         | Response time distribution             |
| Repair job executions         | Counter   | job_name, success                  | Repair execution outcomes              |

### Audit Events

For regulated environments, the following events are available for audit trails:

- **Circuit breaker opened:** Records the breaker name, failure count, and timestamp.
- **Circuit breaker closed:** Records the breaker name, success count, and recovery time.
- **Health check degraded/unhealthy:** Records the check name, status, message, and response time.
- **Repair executed:** Records the job name, diagnosis, actions performed, and success/failure status.

These events can be fed into Pulsar's structured logging and audit logging systems.

### Logging Levels

**RetryPolicy logging (when logger is provided to `execute()`):**

| Level   | Event                               |
| ------- | ----------------------------------- |
| WARNING | Failed attempt with retry scheduled |
| INFO    | Successful retry on attempt > 1     |
| ERROR   | All retry attempts exhausted        |

**Scheduler logging (from `Scheduler` class):**

| Level | Event                                    |
| ----- | ---------------------------------------- |
| INFO  | Tick start, job completion with duration |
| ERROR | Job failure with exception message       |

---

## Complete Example

```php
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\Environment;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\DatabaseHealthCheck;
use Pulsar\Resilience\Repair\RepairRunner;

// 1. Load configuration
$config = ResilienceConfig::fromArray(
    require 'config/resilience.php',
    new Environment(),
);

// 2. Set up retry policy
$retry = RetryPolicy::fromConfig($config->retry);

// 3. Set up circuit breaker registry
$breakers = new CircuitBreakerRegistry($config->circuitBreaker);

// 4. Set up health checks
$healthRunner = new HealthCheckRunner();
$healthRunner->register(new DatabaseHealthCheck($connectionManager));

// 5. Set up repair jobs
$repairRunner = new RepairRunner();
$repairRunner->register(new StaleLockRepairJob($locks));

// 6. Use in application code
class PaymentService
{
    public function __construct(
        private readonly CircuitBreakerRegistry $breakers,
        private readonly RetryPolicy $retry,
    ) {}

    public function processPayment(Payment $payment): PaymentResult
    {
        $breaker = $this->breakers->get('payment-gateway');

        return $breaker->execute(function () use ($payment) {
            $result = $this->retry->execute(
                fn() => $this->gateway->charge($payment),
            );

            if (!$result->succeeded) {
                throw $result->lastException;
            }

            return $result->result;
        });
    }
}

// 7. Run health checks (typically from a console command or HTTP endpoint)
$report = $healthRunner->runAll();
if (!$report->isHealthy()) {
    foreach ($report->results as $result) {
        if ($result->status !== \Pulsar\Resilience\HealthCheck\HealthStatus::Healthy) {
            $logger->warning('Health check issue', [
                'check' => $result->name,
                'status' => $result->status->value,
                'message' => $result->message,
                'response_time_ms' => $result->responseTimeMs,
            ]);
        }
    }
}

// 8. Run repairs
$repairResults = $repairRunner->repairAll();
foreach ($repairResults as $result) {
    $logger->info('Repair completed', [
        'job' => $result->repairJobName,
        'success' => $result->success,
        'actions' => $result->actionsPerformed,
    ]);
}
```

---

## Error Handling

All resilience-specific errors throw `Pulsar\Resilience\Exception\ResilienceException`:

| Factory Method                                                       | When Thrown                                               |
| -------------------------------------------------------------------- | --------------------------------------------------------- |
| `circuitOpen(string $name)`                                          | `CircuitBreaker::execute()` when circuit is in Open state |
| `retryExhausted(string $operation, int $attempts, ?Throwable $last)` | All retry attempts have been exhausted                    |
| `healthCheckFailed(string $checkName, string $reason)`               | `HealthCheckRunner::run()` with unknown check name        |
| `repairFailed(string $repairName, string $reason)`                   | `RepairRunner::repair()` with unknown repair job name     |

---

## Environment Variable Overrides

| Variable             | Overrides                   | Values                |
| -------------------- | --------------------------- | --------------------- |
| `RESILIENCE_ENABLED` | `config.resilience.enabled` | `'true'` or `'false'` |
