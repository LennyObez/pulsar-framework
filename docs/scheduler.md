# Scheduler

## Overview

Pulsar ships a job scheduler for running recurring tasks on a cron-based schedule. The scheduler is designed for mission-critical environments: jobs are registered explicitly, schedules are evaluated deterministically, execution is logged and measured, and all results are inspectable.

The scheduler operates via a "tick" model - a single `scheduler:tick` console command evaluates which jobs are due and runs them synchronously. This command is invoked by your system cron (or equivalent) every minute, keeping the scheduling logic inside PHP and the timing mechanism in the operating system where it belongs.

### Key components

| Class                  | Namespace                    | Purpose                                                    |
| ---------------------- | ---------------------------- | ---------------------------------------------------------- |
| `Scheduler`            | `Pulsar\Scheduler`           | Tick engine: finds due jobs and executes them              |
| `JobInterface`         | `Pulsar\Scheduler`           | Contract for scheduled jobs                                |
| `CallbackJob`          | `Pulsar\Scheduler`           | Closure-based job implementation                           |
| `Schedule`             | `Pulsar\Scheduler`           | Cron-backed schedule definition with static factories      |
| `CronFields`           | `Pulsar\Scheduler`           | Parsed cron expression with matching logic                 |
| `JobRegistry`          | `Pulsar\Scheduler`           | Registry of all scheduled jobs                             |
| `JobContext`           | `Pulsar\Scheduler`           | Context passed to jobs at execution time                   |
| `JobResult`            | `Pulsar\Scheduler`           | Immutable result of a single job execution                 |
| `JobStatus`            | `Pulsar\Scheduler`           | Enum: `Success`, `Failure`, `Skipped`, `Running`           |
| `JobEvent`             | `Pulsar\Scheduler`           | Enum: `BeforeExecute`, `AfterExecute`, `Failed`, `Skipped` |
| `SchedulerTickResult`  | `Pulsar\Scheduler`           | Aggregate result of a scheduler tick                       |
| `SchedulerConfig`      | `Pulsar\Config`              | Typed configuration DTO                                    |
| `SchedulerException`   | `Pulsar\Scheduler\Exception` | Exception type for scheduler errors                        |
| `SchedulerTickCommand` | `Pulsar\Console\Command`     | CLI command: `scheduler:tick`                              |
| `SchedulerListCommand` | `Pulsar\Console\Command`     | CLI command: `scheduler:list`                              |

---

## Configuration reference

All scheduler settings live in `config/scheduler.php`. The `SCHEDULER_ENABLED` environment variable overrides the `enabled` key.

```php
// config/scheduler.php
return [
    'enabled' => false,

    // Timezone used for evaluating cron schedules
    'timezone' => 'UTC',

    // Maximum allowed execution time per job in seconds
    'max_execution_time' => 3600,

    // Whether to log job output to the application logger
    'log_output' => true,
];
```

There is no global lock timeout. Overlap prevention is declared per job, and takes
its lock lifetime from `withoutOverlapping($lock, $expiresAfterMinutes)` — the
lifetime that is correct for a job is that job's own worst-case runtime. A
`lock_timeout` key in `config/scheduler.php` is reported as an unknown key.

### Configuration DTO

```php
use Pulsar\Config\SchedulerConfig;

readonly class SchedulerConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $timezone = 'UTC',
        public int $maxExecutionTime = 3600,
        public bool $logOutput = true,
    ) {}
}
```

---

## Defining jobs

### JobInterface

All scheduled jobs implement `Pulsar\Scheduler\JobInterface`:

```php
interface JobInterface
{
    public function getName(): string;
    public function getSchedule(): Schedule;
    public function execute(JobContext $context): JobResult;
    public function getDescription(): string;
}
```

### CallbackJob

For simple tasks, `CallbackJob` wraps a closure:

```php
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\Schedule;

$job = new CallbackJob(
    name: 'cleanup-temp-files',
    schedule: Schedule::daily(),
    callback: function (JobContext $context): ?string {
        $count = cleanupTempFiles();
        return "Cleaned up {$count} files";  // optional output string
    },
    description: 'Remove temporary files older than 24 hours',
);
```

The callback receives a `JobContext` and may return an optional output string. If the callback throws an exception, the job result is automatically marked as a failure.

### Custom job class

For complex jobs, implement `JobInterface` directly:

```php
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use DateTimeImmutable;

final class DatabaseBackupJob implements JobInterface
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {}

    public function getName(): string
    {
        return 'database-backup';
    }

    public function getSchedule(): Schedule
    {
        return Schedule::dailyAt('02:00', 'America/New_York');
    }

    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $this->backupService->createBackup();
            $context->logger?->info('Database backup completed');

            return JobResult::success($this->getName(), $startedAt, 'Backup created');
        } catch (\Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    public function getDescription(): string
    {
        return 'Daily database backup at 2:00 AM ET';
    }
}
```

### JobContext

The `JobContext` readonly class provides runtime information and services to executing jobs:

```php
readonly class JobContext
{
    public function __construct(
        public DateTimeImmutable $scheduledAt,   // When the tick determined this job was due
        public DateTimeImmutable $startedAt,     // When job execution began
        public ?LoggerInterface $logger = null,  // Application logger
        public ?MetricRegistry $metrics = null,  // Metrics collector
    ) {}
}
```

### JobResult

Job execution returns a `JobResult` readonly value object:

```php
readonly class JobResult
{
    public string $jobName;
    public JobStatus $status;
    public DateTimeImmutable $startedAt;
    public DateTimeImmutable $finishedAt;
    public string $output;
    public ?Throwable $exception;

    // Duration in milliseconds
    public function durationMs(): float;

    // Factory methods
    public static function success(string $jobName, DateTimeImmutable $startedAt, string $output = ''): self;
    public static function failure(string $jobName, DateTimeImmutable $startedAt, Throwable $exception): self;
    public static function skipped(string $jobName): self;
}
```

### JobStatus

```php
enum JobStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';
    case Running = 'running';
}
```

### Preventing overlapping runs

A job that can still be working when its next slot arrives — a nightly reconciliation, a large export — should refuse to start a second copy. `ScheduleBuilder::withoutOverlapping()` does that, and it **requires a lock**:

```php
use Pulsar\Cache\Application\CacheManager;
use Pulsar\Scheduler\ScheduleBuilder;

$lock = $container->get(CacheManager::class)->lock(); // the configured cache pool's lock

$registry->register(
    ScheduleBuilder::job('nightly-reconciliation', $reconcile(...))
        ->dailyAt('02:00')
        ->withoutOverlapping($lock, expiresAfterMinutes: 180)
        ->build(),
);
```

A run that finds the lock held returns `JobStatus::Skipped` without invoking the callback. The lock is released when the job finishes, including when it throws.

`CacheManager::lock($pool)` hands back the `LockInterface` for a configured cache pool, so the backing store follows `config/cache.php` — filesystem, Redis, database, Memcached or APCu — and the pool prefix keeps scheduler locks from colliding with anything else using the same backend. Pass a pool name to pick a specific one.

The lock is not optional and there is no default, because only the caller knows what its scheduler processes share. Each `scheduler:tick` is a separate `cron` invocation, and most production installations run the scheduler on more than one host — so the only thing that can observe a run already in flight is a store all of them reach:

| Scheduler runs on                 | Backing pool must be                     |
| --------------------------------- | ---------------------------------------- |
| One host                          | Filesystem, database, Redis or Memcached |
| Several hosts                     | Redis, Memcached, or the shared database |
| Several hosts with a shared mount | Filesystem on that mount                 |

APCu and the array driver are per-process and cannot see a run started by another tick, so a job locked against either is not protected. Choose the pool deliberately.

Calling `withoutOverlapping()` without a lock is a `TypeError`; constructing a `ScheduledJob` with `preventOverlap: true` and no lock throws `SchedulerException`. Neither can silently report a protection that is absent.

`expiresAfterMinutes` (default `1440`) is the lock's lifetime — the safety valve for a run whose process dies without releasing. Set it above the job's worst-case run time: a lock that expires mid-run lets the next tick start a second copy. When that happens, the release is refused and the job logs a warning naming the job and the expiry.

---

## Schedule and cron syntax

### Schedule static factories

The `Schedule` readonly class provides convenient static factories for common intervals. Each factory accepts an optional timezone string (defaults to `'UTC'`).

| Factory                          | Cron Expression | Description                    |
| -------------------------------- | --------------- | ------------------------------ |
| `Schedule::everyMinute()`        | `* * * * *`     | Every minute                   |
| `Schedule::everyFiveMinutes()`   | `*/5 * * * *`   | Every five minutes             |
| `Schedule::hourly()`             | `0 * * * *`     | Every hour at minute 0         |
| `Schedule::daily()`              | `0 0 * * *`     | Daily at midnight              |
| `Schedule::dailyAt('14:30')`     | `30 14 * * *`   | Daily at 2:30 PM               |
| `Schedule::weekly()`             | `0 0 * * 0`     | Weekly on Sunday at midnight   |
| `Schedule::monthly()`            | `0 0 1 * *`     | Monthly on the 1st at midnight |
| `Schedule::cron('15 3 * * 1-5')` | `15 3 * * 1-5`  | Custom cron expression         |

**Examples:**

```php
use Pulsar\Scheduler\Schedule;

// Every minute
$schedule = Schedule::everyMinute();

// Every 5 minutes in US Eastern time
$schedule = Schedule::everyFiveMinutes('America/New_York');

// Daily at 3:15 AM UTC
$schedule = Schedule::dailyAt('03:15');

// Custom: weekdays at 8:30 AM
$schedule = Schedule::cron('30 8 * * 1-5', 'Europe/London');
```

### Cron syntax reference

Pulsar uses standard 5-field cron expressions:

```
 ┌───────────── minute (0-59)
 │ ┌───────────── hour (0-23)
 │ │ ┌───────────── day of month (1-31)
 │ │ │ ┌───────────── month (1-12)
 │ │ │ │ ┌───────────── day of week (0-6, 0 = Sunday)
 │ │ │ │ │
 * * * * *
```

**Supported field syntax:**

| Syntax    | Meaning           | Example                                    |
| --------- | ----------------- | ------------------------------------------ |
| `*`       | Any value         | `* * * * *` = every minute                 |
| `5`       | Exact value       | `5 * * * *` = at minute 5 of every hour    |
| `1,15,30` | List of values    | `0 1,13 * * *` = at 1:00 AM and 1:00 PM    |
| `1-5`     | Range             | `0 0 * * 1-5` = midnight on weekdays       |
| `*/5`     | Step from 0       | `*/5 * * * *` = every 5 minutes            |
| `1-30/5`  | Step within range | `1-30/5 * * * *` = at 1, 6, 11, 16, 21, 26 |

### Schedule methods

```php
// Check if the schedule is due at a specific time
$schedule = Schedule::cron('*/5 * * * *');
$isDue = $schedule->isDue(new DateTimeImmutable('2025-06-15 10:05:00'));
// true - minute 5 matches */5
```

The `isDue()` method converts the provided time to the schedule's timezone before evaluating.

---

## JobRegistry API

The `JobRegistry` holds all registered jobs and provides lookup and filtering:

```php
use Pulsar\Scheduler\JobRegistry;

$registry = new JobRegistry();

// Register a job (throws SchedulerException on duplicate name)
$registry->register($job);

// Get a job by name (throws SchedulerException if not found)
$job = $registry->get('cleanup-temp-files');

// Check if a job exists
$exists = $registry->has('cleanup-temp-files'); // bool

// Get all registered jobs
$jobs = $registry->all(); // array<string, JobInterface>

// Get all jobs due at a specific time
$dueJobs = $registry->dueJobs(new DateTimeImmutable()); // list<JobInterface>

// Get the total number of registered jobs
$count = $registry->count(); // int
```

---

## Scheduler tick mechanism

The `Scheduler` class orchestrates job execution. Each `tick()` call evaluates all registered jobs, runs those that are due, and returns an aggregate result.

### Constructor

```php
use Pulsar\Scheduler\Scheduler;
use Pulsar\Scheduler\JobRegistry;

$scheduler = new Scheduler(
    registry: $registry,         // JobRegistry
    logger: $logger,             // ?LoggerInterface
    metrics: $metrics,           // ?MetricRegistry
);
```

### tick()

```php
// Execute a tick at the current time
$result = $scheduler->tick();

// Execute a tick at a specific time (useful for testing)
$result = $scheduler->tick(new DateTimeImmutable('2025-06-15 10:00:00'));
```

Returns a `SchedulerTickResult`:

```php
readonly class SchedulerTickResult
{
    public DateTimeImmutable $tickAt;   // When the tick was evaluated
    public array $results;              // list<JobResult>
    public int $jobsDue;                // Number of jobs that were due
    public int $jobsRun;                // Number of jobs executed
    public int $jobsFailed;             // Number of jobs that failed

    public function hasFailures(): bool;
}
```

### runJob()

Run a specific job directly, bypassing schedule evaluation:

```php
$result = $scheduler->runJob($job);
$result = $scheduler->runJob($job, scheduledAt: new DateTimeImmutable());
```

### Logging

The scheduler logs at the following levels:

- **INFO:** Tick start with number of due jobs, job completion with duration.
- **ERROR:** Job failure with exception message.

---

## Console commands

### `scheduler:tick`

Evaluates all registered jobs, runs those that are due, and reports results.

```
php bin/pulsar scheduler:tick
```

**Output example:**

```
  [success] cleanup-temp-files (12.3ms)
  [success] send-daily-report (245.1ms)
  [failure] sync-external-data (1023.4ms)

1/3 job(s) failed.
```

**Exit codes:**

- `0`: All jobs succeeded (or no jobs were due).
- `1`: One or more jobs failed.

### `scheduler:list`

Lists all registered scheduled jobs with their cron expressions and descriptions.

```
php bin/pulsar scheduler:list
```

**Output example:**

```
Registered jobs (3):

  cleanup-temp-files  [0 0 * * *]  Remove temporary files older than 24 hours
  send-daily-report  [0 8 * * 1-5]  Send daily summary email on weekdays
  sync-external-data  [*/5 * * * *]  Sync data from external API every 5 minutes
```

---

## Jobs the framework registers itself

Two of the jobs `scheduler:list` shows are Pulsar's own. Both are registered at boot and both are inert until `scheduler:tick` runs, so enabling the scheduler is what turns them on.

| Job                           | Registered by                  | Schedule from                                               | What it does                                                                                                 |
| ----------------------------- | ------------------------------ | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `data-protection:purge`       | `DataRetentionWiring`          | `purge.schedule` in `config/data_protection.php`            | Applies every retention policy; honours `purge.dry_run`. Set the schedule to `''` to register no job at all. |
| `compliance:collect-evidence` | `ComplianceVerificationWiring` | `verification.evidence_interval` in `config/compliance.php` | Runs compliance verification and appends one HMAC-chained evidence record, at most once per interval.        |

`compliance:collect-evidence` carries its interval twice on purpose. The cron expression decides how often the scheduler considers it — cron cannot express "every N seconds", so the interval is rounded to something cron can say and never rounded up past itself. The elapsed check inside the job decides whether a run actually records, and it reads the last stored record's timestamp rather than process memory, because `scheduler:tick` is a fresh process on every tick.

---

## Deployment

### Running via system cron

Add a single cron entry to invoke `scheduler:tick` every minute. The Pulsar scheduler handles all schedule evaluation internally.

```cron
* * * * * cd /path/to/project && php bin/pulsar scheduler:tick >> /var/log/pulsar-scheduler.log 2>&1
```

On Windows, use Task Scheduler to run the equivalent command every minute:

```
php C:\path\to\project\bin\pulsar scheduler:tick
```

### Recommendations

- Run `scheduler:tick` every minute. Jobs with coarser schedules (hourly, daily) will be skipped automatically when they are not due.
- Direct scheduler output to a log file for post-mortem analysis.
- Monitor the exit code: a non-zero exit indicates at least one job failure.
- Give any job that can outrun its slot a shared lock via `withoutOverlapping()` — see [Preventing overlapping runs](#preventing-overlapping-runs). Every tick is a separate process, so nothing else can stop two copies running at once.

---

## Complete example

```php
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Scheduler;

// 1. Create the registry and register jobs
$registry = new JobRegistry();

$registry->register(new CallbackJob(
    name: 'cleanup-sessions',
    schedule: Schedule::hourly(),
    callback: function ($context): string {
        $count = expireOldSessions();
        $context->logger?->info("Expired {$count} sessions");
        return "Expired {$count} sessions";
    },
    description: 'Remove expired sessions every hour',
));

$registry->register(new CallbackJob(
    name: 'daily-report',
    schedule: Schedule::dailyAt('08:00', 'America/New_York'),
    callback: function ($context): string {
        sendDailyReport();
        return 'Report sent';
    },
    description: 'Send daily summary report at 8 AM ET',
));

$registry->register(new CallbackJob(
    name: 'health-ping',
    schedule: Schedule::everyFiveMinutes(),
    callback: function ($context): string {
        pingHealthEndpoint();
        return 'Ping OK';
    },
    description: 'Ping external health monitoring endpoint',
));

// 2. Create the scheduler
$scheduler = new Scheduler(
    registry: $registry,
    logger: $logger,
    metrics: $metrics,
);

// 3. Run a tick (typically invoked by the scheduler:tick command)
$result = $scheduler->tick();

if ($result->hasFailures()) {
    // Handle failures: alert, retry, etc.
    foreach ($result->results as $jobResult) {
        if ($jobResult->status === \Pulsar\Scheduler\JobStatus::Failure) {
            $logger->error("Job {$jobResult->jobName} failed", [
                'exception' => $jobResult->exception?->getMessage(),
                'duration_ms' => $jobResult->durationMs(),
            ]);
        }
    }
}
```

---

## Error handling

| Exception            | Factory Method                                        | When Thrown                                     |
| -------------------- | ----------------------------------------------------- | ----------------------------------------------- |
| `SchedulerException` | `jobNotFound(string $name)`                           | `JobRegistry::get()` with unknown name          |
| `SchedulerException` | `duplicateJob(string $name)`                          | `JobRegistry::register()` with duplicate name   |
| `SchedulerException` | `executionTimeout(string $name, int $timeout)`        | Job exceeds max execution time                  |
| `SchedulerException` | `invalidCronExpression(string $expr, string $reason)` | `CronFields::parse()` with malformed expression |

---

## Environment variable overrides

| Variable            | Overrides                  | Values                |
| ------------------- | -------------------------- | --------------------- |
| `SCHEDULER_ENABLED` | `config.scheduler.enabled` | `'true'` or `'false'` |
