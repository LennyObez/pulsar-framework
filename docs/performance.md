# Performance benchmarking

Pulsar includes a benchmark harness that measures the performance impact of OPcache, JIT, and preloading configurations.

## Running benchmarks

```bash
php tools/bench/run.php
```

The harness spawns fresh PHP processes for each configuration profile, ensuring isolated measurements. Results are written atomically to `var/bench/results.json` with stable key order for clean diffs.

### Custom output path

```bash
php tools/bench/run.php --output=path/to/results.json
```

## What is measured

| Metric    | Description                                                |
| --------- | ---------------------------------------------------------- |
| Boot (us) | Cold-boot time - kernel creation, route registration, boot |
| p50 (us)  | Median per-request latency over 1000 iterations            |
| p95 (us)  | 95th percentile per-request latency                        |
| RPS       | Estimated requests per second (from measured iterations)   |
| RSS (KB)  | Peak resident set size (`memory_get_peak_usage(true)`)     |

## Profiles

The harness tests 6 combinations defined in `tools/bench/profiles.json`:

| Profile                | OPcache | JIT      | Preload |
| ---------------------- | ------- | -------- | ------- |
| `baseline`             | On      | Off      | No      |
| `jit-tracing`          | On      | Tracing  | No      |
| `jit-function`         | On      | Function | No      |
| `preload-only`         | On      | Off      | Yes     |
| `preload-jit-tracing`  | On      | Tracing  | Yes     |
| `preload-jit-function` | On      | Function | Yes     |

All profiles use `opcache.enable_cli=1` since CLI without OPcache would produce misleading results.

## Interpreting results

- **p50 vs p95**: The p50 (median) shows typical performance. The p95 shows worst-case latency. A large gap indicates occasional slow requests (GC pauses, JIT compilation, etc.).
- **RSS**: Peak memory usage. Preloading increases baseline RSS since classes are loaded into shared memory at startup.
- **RPS**: Throughput estimate. Higher is better. This is computed from the total measured time, not wall-clock time.
- **Boot time**: Primarily useful for CLI tools and serverless environments where cold-start matters.

## Caveats

- **CLI vs FPM**: CLI SAPI results may differ significantly from FPM. JIT warmup behavior and memory allocation differ between SAPIs. Always validate with your production SAPI.
- **JIT warmup**: JIT benefits increase after warmup iterations. The harness runs 200 warmup iterations before measuring.
- **Workload dependency**: JIT benefits vary by workload. CPU-intensive operations benefit most; I/O-bound workloads may see little difference.
- **System noise**: Run benchmarks on a quiet system for reproducible results. Close other applications and avoid running during background tasks.

## Temporary preload files

The benchmark harness generates temporary preload scripts solely for benchmarking. These are cleaned up after the run. For production, use the `preload:dump` command to create an immutable build artifact:

```bash
php bin/pulsar preload:dump --output=preload.generated.php
```

See [`docs/deployment.md`](deployment.md) for production configuration.

## Results file format

`results.json` uses stable key order (alphabetical) for deterministic diffs:

```json
{
  "baseline": {
    "boot_us": 487,
    "iterations": 1000,
    "p50_us": 42,
    "p95_us": 68,
    "peak_rss_kb": 12540,
    "rps": 23800
  }
}
```

## Performance budgets

Pulsar uses a tiered benchmark system to enforce performance budgets across all request classes, crypto primitives, audit operations, component operations, and memory usage.

### Tiered benchmark system

#### Tier A: hard gate (every PR)

- **Runs on**: every pull request as a required CI check
- **Environment**: in-memory drivers only (no databases, caches, or message brokers)
- **Assertion**: median (p50) vs absolute budget, +/- 5% tolerance
- **Failure policy**: budget violations **block merge**
- **Covers**: container, routing, middleware, request lifecycle, crypto (AEAD, HMAC), audit, session, validation, memory peak

#### Tier B: nightly (realistic environment)

- **Runs on**: scheduled nightly (3 AM UTC) or manual trigger
- **Environment**: real Redis, PostgreSQL, AMQP containers
- **Assertion**: p90 vs budget, +/- 15% tolerance
- **Failure policy**: regression alerts (notification), not hard gate
- **Covers**: all Tier A benchmarks plus real-backend session, audit, and queue benchmarks

#### Tier C: RC gate (controlled runner)

- **Runs on**: manually triggered before tagging an RC release
- **Environment**: real backends on controlled runner (self-hosted or pinned instance)
- **Assertion**: p90 vs budget, +/- 10% tolerance
- **Failure policy**: must pass before RC is tagged
- **Covers**: all Tier B benchmarks plus `crypto.kdf` (Argon2id)

### Component-level budgets

All component-level budgets are defined in `tools/php/performance-budgets.json`:

| Budget ID                       | Max average      | Description                                                    |
| ------------------------------- | ---------------- | -------------------------------------------------------------- |
| `container.instance_resolution` | 100 microseconds | `Container::get` for pre-registered instances (direct lookup)  |
| `container.singleton_cached`    | 100 microseconds | `Container::get` for cached singletons                         |
| `container.factory_resolution`  | 1 millisecond    | `Container::get` for factory bindings (new instance each time) |
| `router.static_10`              | 5 microseconds   | `Router::match` against 10 static routes                       |
| `router.static_200`             | 100 microseconds | `Router::match` against 200 static routes (worst case)         |
| `router.parameterized`          | 200 microseconds | `Router::match` with parameter extraction                      |
| `middleware.pipeline_5`         | 10 microseconds  | Middleware pipeline with 5 pass-through layers                 |
| `request.creation`              | 5 microseconds   | `Request` object construction                                  |
| `response.creation`             | 5 microseconds   | `Response` object construction via static factory              |
| `validation.simple`             | 50 microseconds  | Validation of 5 fields with simple rules                       |
| `kernel.dispatch`               | 500 microseconds | Full kernel request dispatch cycle                             |
| `authorization.gate_check`      | 50 microseconds  | Authorization gate check with policies                         |

#### Budget categories

**Sub-10us (near-zero overhead):**
Router static lookup (10 routes), middleware pipeline, request creation, response creation. These operations happen on every request and must be virtually free.

**Sub-100us (fast):**
Container resolution (instances and singletons), router static lookup (200 routes), validation, authorization gate checks. These are common per-request operations.

**Sub-1ms (bounded):**
Container factory resolution, kernel dispatch. These involve more work (object construction, full lifecycle) but remain well under the 1ms threshold.

### Request-class budgets

End-to-end request lifecycle benchmarks exercising realistic middleware stacks:

| Budget key                      | FPM target | Persistent target | Description                            |
| ------------------------------- | ---------- | ----------------- | -------------------------------------- |
| `request.anonymous_json_api`    | < 2 ms     | < 1 ms            | Anonymous JSON API (route + serialize) |
| `request.authenticated_session` | < 3 ms     | < 2 ms            | Session-authenticated request          |
| `request.authenticated_token`   | < 2 ms     | < 1.5 ms          | Token-authenticated request            |
| `request.with_audit`            | < 4 ms     | < 3 ms            | Request with audit trail (HMAC-signed) |
| `request.compliance_event`      | < 5 ms     | < 4 ms            | Request with compliance event dispatch |

### Security primitive budgets

| Budget key                   | Target   | Tier | Description                                 |
| ---------------------------- | -------- | ---- | ------------------------------------------- |
| `crypto.encrypt.aead_1024b`  | < 100 us | A    | XChaCha20-Poly1305 AEAD, 1024-byte payload  |
| `crypto.envelope_encrypt`    | < 200 us | A    | Envelope encryption (KDF excluded)          |
| `crypto.hmac_sign`           | < 50 us  | A    | BLAKE2b keyed hash for audit signing        |
| `crypto.kdf`                 | < 10 ms  | C    | Argon2id key derivation (controlled runner) |
| `audit.log_write`            | < 500 us | A    | Single audit entry with HMAC chain          |
| `audit.chain_verify_128`     | < 1 ms   | A    | Verify 128-entry audit chain                |
| `session.load_verify.memory` | < 500 us | A    | Session load + fingerprint validation       |

### Memory budgets

| Budget key                  | Target | Enforcement | Description                        |
| --------------------------- | ------ | ----------- | ---------------------------------- |
| `memory.peak_anonymous`     | < 2 MB | Hard gate   | Peak memory for anonymous request  |
| `memory.peak_authenticated` | < 4 MB | Hard gate   | Peak memory for authenticated req  |
| `memory.peak_compliance`    | < 6 MB | Hard gate   | Peak memory for compliance req     |
| `allocations.anonymous`     | < 500  | Advisory    | Object allocations (anonymous)     |
| `allocations.authenticated` | < 1000 | Advisory    | Object allocations (authenticated) |

### Runtime-specific budgets

Separate budget files for different PHP runtimes:

- `tools/php/budgets.fpm.json`: PHP-FPM (cold bootstrap, per-request process)
- `tools/php/budgets.persistent.json`: RoadRunner/FrankenPHP (warm container, amortized bootstrap)

Persistent runtimes have lower budgets for request classes since bootstrap cost is amortized.

### Request-class semantic contracts

Each request class has an explicit contract defining what the benchmark must exercise:

| Budget key                      | Required middleware                                               | Auth    | Side-effects                 |
| ------------------------------- | ----------------------------------------------------------------- | ------- | ---------------------------- |
| `request.anonymous_json_api`    | routing, error-handler, content-negotiation                       | None    | None                         |
| `request.authenticated_session` | routing, error-handler, session-start, auth-guard, authorization  | Session | Session read/write           |
| `request.authenticated_token`   | routing, error-handler, token-resolver, auth-guard, authorization | Bearer  | Token validation             |
| `request.with_audit`            | All session + audit-writer                                        | Session | Session + audit write (HMAC) |
| `request.compliance_event`      | All audit + compliance-dispatcher                                 | Session | Session + audit + compliance |

The manifest validator checks these contracts before benchmark execution. Removing required middleware or side-effects from a request class fails CI.

### How budgets are enforced

#### PHPBench

Benchmarks run with [PHPBench](https://phpbench.readthedocs.io/), configured in `tools/php/phpbench.json`:

```json
{
  "runner.bootstrap": "../../vendor/autoload.php",
  "runner.path": "../../tests/Benchmark",
  "runner.file_pattern": "*Bench.php",
  "runner.iterations": [5],
  "runner.revs": [1000],
  "runner.warmup": [1],
  "runner.retry_threshold": 5,
  "runner.time_unit": "microseconds",
  "runner.assert": "mode(variant.time.avg) < 10 milliseconds"
}
```

Configuration breakdown:

- **iterations**: 5. Each benchmark runs 5 times to measure variance.
- **revs**: 1000. Each iteration executes the benchmark 1000 revolutions for statistical stability.
- **warmup**: 1. One warmup iteration runs before measurement to prime caches and JIT.
- **retry_threshold**: 5. Benchmarks with >5% relative standard deviation are retried to filter noise.
- **time_unit**: microseconds. All results are reported in microseconds.
- **assert**: global assertion that all benchmarks complete under 10ms (individual budgets are tighter).

#### Benchmark assertions

Individual budgets are enforced via PHPBench `#[Assert]` attributes on each benchmark method:

```php
#[Assert('mode(variant.time.avg) < 100 microseconds')]
public function benchContainerInstanceResolution(): void
{
    $this->container->get(SomeService::class);
}
```

If the assertion fails, PHPBench exits with a non-zero code and the CI job fails.

#### CI integration

The benchmark suite runs as an **advisory** CI job for Tier A. Results appear in the PR job summary and are uploaded as a build artifact (14-day retention) for human review.

```bash
composer bench
```

This command runs PHPBench with the configuration from `tools/php/phpbench.json` and the custom `pulsar` report generator, which outputs columns for benchmark name, subject name, parameter set, revolutions, iterations, peak memory, mode time, and relative standard deviation.

### Statistical assertion policy

All benchmark assertions use statistical methods to reduce flakiness:

- **Warmup**: N warmup iterations discarded before measurement (default: 5)
- **Measured iterations**: minimum 50 iterations per benchmark
- **Tier A**: assert on median (p50), +/- 5% tolerance
- **Tier B**: assert on p90, +/- 15% tolerance
- **Tier C**: assert on p90, +/- 10% tolerance
- **Regression detection**: alert if p50 regresses > 5% across 3 consecutive runs

### Memory peak measurement

Memory peak budgets use `memory_get_peak_usage(true)` in isolated PHP processes. Each scenario runs in a fresh process to ensure accurate per-scenario peak measurement (the peak value cannot be reset within a running process).

Implementation: each `tests/Benchmark/Scenarios/memory_*.php` script autoloads, builds the relevant request pipeline, processes one request, and outputs the peak memory in bytes.

### Running benchmarks locally

```bash
# Run all benchmarks (Tier A)
composer bench

# Run benchmarks with CI output format
composer bench:ci

# Run OPcache/JIT/preload profile matrix
composer bench:profiles

# Run a single benchmark class
vendor/bin/phpbench run --config=tools/php/phpbench.json --filter=CryptoBench

# Run a specific benchmark file
vendor/bin/phpbench run tests/Benchmark/ContainerBench.php --config=tools/php/phpbench.json

# Run with detailed report
vendor/bin/phpbench run --config=tools/php/phpbench.json --report=pulsar

# Run without assertions (for profiling)
vendor/bin/phpbench run --config=tools/php/phpbench.json --assert=none

# Run memory peak scenarios
php tests/Benchmark/Scenarios/memory_anonymous.php
php tests/Benchmark/Scenarios/memory_authenticated.php
php tests/Benchmark/Scenarios/memory_compliance.php

# Compare against a baseline
vendor/bin/phpbench run --config=tools/php/phpbench.json --tag=baseline
vendor/bin/phpbench run --config=tools/php/phpbench.json --ref=baseline --report=pulsar
```

### Adding a new benchmark

#### 1. Create a benchmark class

Benchmark files live in `tests/Benchmark/` and must match the pattern `*Bench.php`:

```php
<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use Pulsar\Security\Session\ArraySession;

#[BeforeMethods('setUp')]
class SessionBench
{
    private ArraySession $session;

    public function setUp(): void
    {
        $this->session = new ArraySession();
    }

    #[Revs(1000)]
    #[Iterations(5)]
    #[Assert('mode(variant.time.avg) < 10 microseconds')]
    public function benchSessionWrite(): void
    {
        $this->session->set('key', 'value');
    }

    #[Revs(1000)]
    #[Iterations(5)]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchSessionRead(): void
    {
        $this->session->get('key');
    }
}
```

#### 2. Add the budget to performance-budgets.json

Update `tools/php/performance-budgets.json` with the new budget:

```json
{
  "session.write": {
    "max_avg": "10 microseconds",
    "description": "Session write for a single key-value pair"
  },
  "session.read": {
    "max_avg": "5 microseconds",
    "description": "Session read for a single key"
  }
}
```

#### 3. Add runtime-specific overrides (if needed)

If the budget varies by runtime, add overrides to `budgets.fpm.json` and `budgets.persistent.json`.

#### 4. For request-class benchmarks

Update `bench-pipeline.manifest.php` with the semantic contract.

#### 5. For memory benchmarks

Create a scenario script in `tests/Benchmark/Scenarios/`.

#### 6. Verify locally

```bash
composer bench
```

Ensure the new benchmarks pass assertions before committing. All budget changes require Performance Engineer sign-off.

### Pipeline manifest governance

The file `tools/php/bench-pipeline.manifest.php` declares the exact middleware stacks and storage backends for each request-class benchmark. This manifest is content-hashed in CI; unauthorized changes fail the build.

#### Changing the manifest

1. Any change requires **Performance Engineer sign-off**
2. If the change reduces security/compliance coverage, **Architecture sign-off** is also required
3. Changes must include: measured justification, before/after data, rationale
4. Budget relaxations (raising thresholds) require additional Architecture sign-off

### Budget tuning guide

#### When to adjust budgets

- **Tighten** budgets after optimizing a subsystem. If container resolution consistently runs at 30us, tighten the budget from 100us to 50us to lock in the improvement.
- **Loosen** budgets only with justification. If a feature adds necessary complexity (e.g., parameter extraction adds overhead to routing), document the reason and adjust the budget proportionally.
- **Never remove** a budget. If a subsystem is deprecated, mark the budget as deprecated in the JSON file rather than deleting it.

#### Tuning process

1. Run benchmarks locally multiple times to establish a stable baseline.
2. Check the relative standard deviation (rstdev). If rstdev > 5%, the benchmark may be noisy and needs investigation before tightening.
3. Set the budget to approximately 2x the observed mode to account for CI environment variance (CI runners may be slower than development machines).
4. Update both the `#[Assert]` attribute in the benchmark file and the `max_avg` in `performance-budgets.json`.
5. Submit the change and verify that CI passes consistently across multiple runs.

#### Environment considerations

Benchmark times vary between environments. The budgets are calibrated for:

- Standard CI runners (shared CPU, no dedicated hardware)
- PHP 8.5 with OPcache enabled
- No Xdebug or other profiling extensions loaded

Local development machines with faster CPUs will typically run under budget. If your local results diverge significantly from CI, check for:

- Xdebug loaded (`php -m | grep xdebug`)
- OPcache disabled (`php -i | grep opcache.enable`)
- Thermal throttling on laptops
- Background processes consuming CPU

### Budget files

| File                                    | Purpose                               |
| --------------------------------------- | ------------------------------------- |
| `tools/php/performance-budgets.json`    | All Tier A budget definitions         |
| `tools/php/budgets.fpm.json`            | FPM-specific overrides                |
| `tools/php/budgets.persistent.json`     | Persistent-runtime overrides          |
| `tools/php/bench-pipeline.manifest.php` | Pipeline contracts and storage config |
| `tools/php/phpbench.json`               | PHPBench runner configuration         |

### Related docs

- [Observability](observability.md): metric collection for production performance monitoring
