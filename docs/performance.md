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

- **Runs on**: every pull request, as the `php-benchmark-tier-a` job
- **Environment**: in-memory drivers only (no databases, caches, or message brokers), with
  OPcache on, JIT off and Xdebug off pinned by `runner.php_config`
- **Assertion**: `mode(variant.time.avg)` against an absolute budget, no tolerance band —
  the budget is the number in the `#[Assert]` attribute and nothing widens it
- **Failure policy**: budget violations **block merge**. PHPBench exits non-zero on a
  breach and the step propagates it (the job sets `shell: bash`, so the pipe into `tee`
  does not swallow the status)
- **Covers**: container, routing, middleware, request lifecycle, crypto (AEAD, HMAC), audit, session, validation, memory peak

#### Tier B: nightly (realistic environment)

- **Runs on**: scheduled nightly (3 AM UTC) or manual trigger
- **Environment**: real Redis, PostgreSQL, AMQP containers
- **Assertion**: the same absolute `#[Assert]` budgets as Tier A, measured against real
  backends
- **Failure policy**: advisory. The run steps carry `continue-on-error: true`, so a breach
  marks the step and leaves the workflow green
- **Covers**: all Tier A benchmarks plus real-backend session, audit, and queue benchmarks

#### Tier C: RC gate (controlled runner)

- **Runs on**: manually triggered before tagging an RC release
- **Environment**: real backends on controlled runner (self-hosted or pinned instance)
- **Assertion**: the same absolute `#[Assert]` budgets, plus the memory-peak scenarios
- **Failure policy**: the job fails on a breach, and passing it is a precondition of
  tagging the RC. It is triggered by hand (`workflow_dispatch`), so nothing enforces that
  it was run — the release checklist does
- **Covers**: all Tier B benchmarks plus `crypto.kdf` (Argon2id)

#### Regression check: relative, every PR

Separate from the tiers, and the only relative gate: `.github/workflows/benchmark-regression.yml`
runs on any pull request touching `src/`, `extensions/` or `benchmarks/`. It measures the
merge base and the pull request **in the same job on the same runner**, then refuses
anything more than 5% slower. Both halves run under the same CPU, PHP build and load,
which is the only arrangement in which a 5% threshold means what it says. It is
merge-blocking.

### Component-level budgets

The budgets that fail a build are the `#[Assert]` attributes on the subjects in
`tests/Benchmark` — 77 of them across 24 classes. `tools/php/performance-budgets.json`
is a **reference table read by no code**; it exists so the whole set can be read in one
place, and it can only be trusted as far as the last person who kept it in step with the
attributes. When the two disagree, the attribute is the budget. See
[ADR-0072](adr/0072-a-budget-is-the-assertion-that-runs.md).

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
| `kernel.dispatch`               | 400 microseconds | Steady-state dispatch on an already-booted kernel              |
| `kernel.boot_and_dispatch`      | 30 milliseconds  | Cold boot plus one dispatch (`#[RetryThreshold(25)]`)          |
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

### The standalone `benchmarks/` tree

`tools/php/phpbench.json` sets `runner.path` to `tests/Benchmark`, so `composer bench`
and every CI tier above measure **only** that tree. A second tree, `benchmarks/`, holds
component benchmarks that are run by giving PHPBench an explicit path. They are not a
merge gate; they are the harness you reach for when a change touches the ORM or when a
memory regression needs attributing to a component rather than to a request class.

Their budgets are stated here because they are asserted in the benchmark classes
themselves rather than in `performance-budgets.json`, and a budget nobody can find is a
budget nobody defends.

#### ORM query builder — `benchmarks/Orm/QueryBuilderBench.php`

| Subject                         | Budget   |
| ------------------------------- | -------- |
| Simple `SELECT`                 | < 50 us  |
| Complex `SELECT` (5 conditions) | < 100 us |
| `SELECT` with `JOIN`            | < 100 us |
| `SELECT` with `LIKE`            | < 50 us  |
| `INSERT` build                  | < 50 us  |
| `UPDATE` build                  | < 50 us  |
| `DELETE` build                  | < 50 us  |
| 10 sequential `SELECT` builds   | < 500 us |

#### ORM hydration — `benchmarks/Orm/HydrationBench.php`

| Subject             | Budget   |
| ------------------- | -------- |
| Single entity       | < 20 us  |
| 10 entities         | < 100 us |
| 100 entities        | < 1 ms   |
| 1000 entities       | < 10 ms  |
| Dehydrate (INSERT)  | < 20 us  |
| Dehydrate (UPDATE)  | < 20 us  |
| Extract primary key | < 10 us  |

#### ORM relations — `benchmarks/Orm/RelationBench.php`

| Subject                         | Budget  |
| ------------------------------- | ------- |
| Eager-load HasMany (50 parents) | < 10 ms |
| `withCount` (50 parents)        | < 5 ms  |
| Batch-load 500 IDs              | < 10 ms |
| FetchPlan create + merge        | < 10 us |
| Nested FetchPlan                | < 10 us |
| Empty relation load             | < 5 us  |
| No-op FetchPlan                 | < 5 us  |

#### Component memory — `benchmarks/Memory/MemoryProfileBench.php`

These measure an allocation **delta** around a component operation, not a process peak,
which is what makes them attributable: `memory.peak_anonymous` tells you a request grew,
these tell you which part of it did.

| Subject                  | Budget   | Measurement               |
| ------------------------ | -------- | ------------------------- |
| Route registration (200) | < 512 KB | `memory_get_usage(true)`  |
| Route matching (100x)    | < 256 KB | `memory_get_usage(true)`  |
| Container (100 services) | < 256 KB | `memory_get_usage(true)`  |
| Entity hydration (1000)  | < 1 MB   | `memory_get_usage(true)`  |
| JSON response (100x)     | < 512 KB | `memory_get_usage(true)`  |
| Full request cycle       | < 2 MB   | `memory_get_peak_usage()` |

A memory budget cannot be written as a PHPBench `#[Assert]`, because PHPBench asserts over
timing expressions. Each subject therefore measures its own delta and throws a
`RuntimeException` naming the byte count when it exceeds the budget — a failed revolution,
which fails the run. The `#[Assert('mode(variant.time.avg) < 30 seconds')]` on those
subjects is not the budget; it is a hang guard, and reading it as the budget would be
reading these subjects as unenforced.

#### Boot cost — `benchmarks/Boot/`

`ConfigManagerBench` and `ExtensionBootstrapBench` measure config loading and manifest
scanning. They carry **no budget**: boot cost is dominated by how many extensions a
deployment installs, so an absolute number would fail on a large install and pass on an
empty one. They exist to be compared against a tagged baseline, not against a threshold.

#### Running them

```bash
# ORM component benchmarks
vendor/bin/phpbench run benchmarks/Orm/ --config=tools/php/phpbench.json --report=pulsar

# Component memory profile
vendor/bin/phpbench run benchmarks/Memory/ --config=tools/php/phpbench.json --report=pulsar

# Boot cost
vendor/bin/phpbench run benchmarks/Boot/ --config=tools/php/phpbench.json --report=pulsar

# Cross-framework comparison (standalone script, not PHPBench)
php benchmarks/Comparative/PulsarBench.php --iterations=10000 --json
```

#### Why these budgets are where they are

Budgets in this tree are set at **2-5x observed typical performance**: tight enough that a
regression of the kind that matters — an accidental N+1, a hydration path that stopped
reusing its reflection cache — shows up, loose enough that CI variance does not. Tighten
them as a component stabilises; a budget that has never been near its limit is measuring
nothing. The observation environment is PHP 8.5 with OPcache on, JIT off, and neither
Xdebug nor PCOV loaded, which `tools/php/phpbench.json` sets through `runner.php_config`
so a local run and a CI run agree.

### Runtime-specific budgets

Two further reference files, read by no code, recording what each runtime is expected to
cost for the five request classes:

- `tools/php/budgets.fpm.json`: PHP-FPM (cold bootstrap, per-request process). These are
  the numbers `tests/Benchmark/EndToEndBench.php` and `MemoryProfileBench.php` assert, so
  this file has an enforced counterpart even though nothing reads the file itself.
- `tools/php/budgets.persistent.json`: RoadRunner/FrankenPHP (warm container, amortized
  bootstrap). **Nothing measures these.** `tests/Benchmark` exercises the FPM-shaped path
  only, so the warm-path targets are a written expectation and not a gate. Treat them as
  the number to design against, never as a number something checked.

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
  "runner.retry_threshold": 20,
  "runner.time_unit": "microseconds",
  "runner.assert": "mode(variant.time.avg) < 10 milliseconds",
  "runner.php_config": {
    "opcache.enable_cli": "1",
    "opcache.jit": "off",
    "opcache.jit_buffer_size": "0",
    "xdebug.mode": "off"
  }
}
```

Configuration breakdown:

- **iterations**: 5. Each benchmark runs 5 times to measure variance.
- **revs**: 1000. Each iteration executes the benchmark 1000 revolutions for statistical stability.
- **warmup**: 1. One warmup iteration runs before measurement to prime caches and JIT.
- **retry_threshold**: 20. PHPBench re-runs any iteration deviating more than the threshold
  from the variant mean, in a `while (getRejectCount() > 0)` loop with no limit — phpbench 1.7
  never calls `SubjectMetadata::setRetryLimit()`. At 5, a variant that cannot settle does not
  fail, it spins: one CMS subject retried more than ten times locally before landing at 3.3%,
  and the CMS group alone did not finish inside twenty minutes. The threshold was never the
  gate; the `#[Assert]` budgets are, and they have margin to spare over a 20% sample.
  Individual subjects override it with `#[RetryThreshold]` where their spread is wider.
- **time_unit**: microseconds. All results are reported in microseconds.
- **assert**: global assertion that all benchmarks complete under 10ms (individual budgets are tighter).
- **php_config**: pins the child interpreter — OPcache on, JIT off, Xdebug off — so a budget
  means the same thing on CI as on the machine it was derived on. Xdebug alone moved the
  kernel-boot subject by 2.2x. Nothing in the Tier A step should set ini flags; doing so
  would silently invalidate every budget in `tests/Benchmark`.

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

The Tier A job (`Tier A: Performance Budgets (Hard Gate)` in `.github/workflows/ci.yml`) **blocks the merge**. It carries no `continue-on-error`, and it sets `shell: bash` so that `pipefail` is on - without that line the steps that pipe PHPBench into `tee` would exit with `tee`'s status and every `#[Assert]` in `tests/Benchmark` would be unenforceable. A breached budget exits 2 and fails the job.

Three steps in it gate independently: the pipeline manifest integrity check (`composer bench:manifest:check`), the PHPBench assertions (`composer bench:ci`), and the OPcache/JIT/preload profile matrix (`composer bench:profiles:ci`). None is advisory.

Results also appear in the PR job summary and are uploaded as a build artifact (14-day retention) for human review - that reporting is in addition to the gate, not instead of it.

```bash
composer bench
```

This command runs PHPBench with the configuration from `tools/php/phpbench.json` and the custom `pulsar` report generator, which outputs columns for benchmark name, subject name, parameter set, revolutions, iterations, peak memory, mode time, and relative standard deviation.

### Statistical assertion policy

One policy, applied by `tools/php/phpbench.json` to every tier, because all three tiers run
the same `composer bench:ci`:

- **Warmup**: 1 iteration, discarded.
- **Measured iterations**: 5, of 1000 revolutions each, unless a subject overrides `#[Revs]`.
- **Statistic**: `mode(variant.time.avg)` — the mode of the per-iteration averages, which is
  what `#[Assert]` compares against the budget. Not a percentile, and no tolerance band.
- **Noise handling**: iterations deviating more than 20% from the variant mean are re-run
  (`runner.retry_threshold`), with `#[RetryThreshold]` widening it per subject where needed.
- **Relative regression**: handled by `benchmark-regression.yml`, not by the budgets — 5%
  against the merge base, measured on the same runner in the same job.

There is no coefficient-of-variation logic that promotes or demotes a budget between
blocking and advisory. What blocks is decided per workflow and written in the workflow.

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

#### 2. Record it in the reference table

The `#[Assert]` attribute above is the budget — that is what CI enforces. Add the same
number to `tools/php/performance-budgets.json` so the set stays readable in one place:

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

If the number differs by runtime, record it in `budgets.fpm.json` or
`budgets.persistent.json`. Both are reference tables read by no code — writing a number
there changes nothing a build checks, and an FPM-specific budget is only enforced once it
is an `#[Assert]` on a subject in `tests/Benchmark/EndToEndBench.php` or
`MemoryProfileBench.php`. The persistent-runtime file has no enforced counterpart at all.

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

The file `tools/php/bench-pipeline.manifest.php` declares the exact middleware stacks and storage backends for each request-class benchmark. Its SHA-256 is recorded in `tools/php/bench-pipeline.manifest.sha256`, and `tools/ci/assert-bench-manifest-integrity.php` compares the two on every Tier A run: a manifest that has changed while the recorded digest has not fails the build.

What that buys, stated narrowly: it makes a **silent** change to the benchmark contract impossible. Anyone who can edit the manifest can also re-run `--update`, so this is not a defence against a determined author — it is a guarantee that changing what the benchmarks measure means touching a file that exists for no other purpose, in the same commit, where a reviewer sees it. The sign-offs below are human steps that the gate makes visible and does not perform.

Before this existed, CI computed the digest, printed it into the job summary, and compared it to nothing, while this page described it as a gate. `BenchPipelineManifestGateTest` now plants a manifest edited without its digest, a digest edited without its manifest, a missing digest and an unreadable one, and observes each refusal.

#### Changing the manifest

1. Any change requires **Performance Engineer sign-off**
2. If the change reduces security/compliance coverage, **Architecture sign-off** is also required
3. Changes must include: measured justification, before/after data, rationale
4. Budget relaxations (raising thresholds) require additional Architecture sign-off
5. Re-derive any budget the change affects, then run `composer bench:manifest:record` and commit `tools/php/bench-pipeline.manifest.sha256` alongside the manifest

### Budget tuning guide

#### When to adjust budgets

- **Tighten** budgets after optimizing a subsystem. If container resolution consistently runs at 30us, tighten the budget from 100us to 50us to lock in the improvement.
- **Loosen** budgets only with justification. If a feature adds necessary complexity (e.g., parameter extraction adds overhead to routing), document the reason and adjust the budget proportionally.
- **Never remove** a budget. If a subsystem is deprecated, mark the `#[Assert]` and its row in the reference JSON as deprecated rather than deleting either — deleting the attribute is what silently ends the enforcement, and deleting only the JSON row leaves the gate in place with nothing describing it.

#### Tuning process

1. Run benchmarks locally multiple times to establish a stable baseline.
2. Check the relative standard deviation (rstdev). If rstdev > 5%, the benchmark may be noisy and needs investigation before tightening.
3. Set the budget to approximately 2x the observed mode to account for CI environment variance (CI runners may be slower than development machines).
4. Change the `#[Assert]` attribute in the benchmark file — that is the budget — and update
   the `max_avg` in `performance-budgets.json` to match, so the reference table does not
   start describing a gate that no longer exists.
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

| File                                       | Purpose                                                       |
| ------------------------------------------ | ------------------------------------------------------------- |
| `tests/Benchmark/**Bench.php`              | **The budgets.** 77 `#[Assert]` attributes; these fail builds |
| `tools/php/performance-budgets.json`       | Reference table of the above. Read by no code                 |
| `tools/php/budgets.fpm.json`               | Reference table, FPM request classes. Read by no code         |
| `tools/php/budgets.persistent.json`        | Reference table, persistent runtimes. Measured by nothing     |
| `tools/php/bench-pipeline.manifest.php`    | Pipeline contracts and storage config                         |
| `tools/php/bench-pipeline.manifest.sha256` | Recorded digest of the manifest, compared on every Tier A run |
| `tools/php/phpbench.json`                  | PHPBench runner configuration                                 |

### Related docs

- [Observability](observability.md): metric collection for production performance monitoring
