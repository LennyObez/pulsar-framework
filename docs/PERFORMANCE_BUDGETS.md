# Performance Budgets

Pulsar enforces performance budgets on critical framework operations. Every budget has a defined maximum average execution time, verified by automated benchmarks in CI. This ensures that regressions in hot-path performance are caught before they reach production.

## Why Performance Budgets Matter

In regulated, mission-critical domains (banking, healthcare, legal), predictable performance is a compliance and reliability requirement. Performance budgets provide:

- **Regression prevention** -- A commit that degrades container resolution from 80us to 500us will fail CI.
- **Measurable targets** -- Teams can reason about framework overhead with concrete numbers.
- **Architectural accountability** -- Each subsystem has an explicit performance contract.
- **Capacity planning** -- Known per-request overhead makes load testing and sizing predictable.

## Budget Table

All budgets are defined in `tools/php/performance-budgets.json`:

| Budget ID                       | Max Average      | Description                                                    |
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

### Budget Categories

**Sub-10us (near-zero overhead):**
Router static lookup (10 routes), middleware pipeline, request creation, response creation. These operations happen on every request and must be virtually free.

**Sub-100us (fast):**
Container resolution (instances and singletons), router static lookup (200 routes), validation, authorization gate checks. These are common per-request operations.

**Sub-1ms (bounded):**
Container factory resolution, kernel dispatch. These involve more work (object construction, full lifecycle) but remain well under the 1ms threshold.

## How Budgets Are Enforced

### PHPBench

Benchmarks are run with [PHPBench](https://phpbench.readthedocs.io/), configured in `tools/php/phpbench.json`:

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

- **iterations**: 5 -- Each benchmark is run 5 times to measure variance.
- **revs**: 1000 -- Each iteration executes the benchmark 1000 revolutions for statistical stability.
- **warmup**: 1 -- One warmup iteration is run before measurement to prime caches and JIT.
- **retry_threshold**: 5 -- Benchmarks with >5% relative standard deviation are retried to filter noise.
- **time_unit**: microseconds -- All results are reported in microseconds.
- **assert**: Global assertion that all benchmarks complete under 10ms (individual budgets are tighter).

### Benchmark Assertions

Individual budgets are enforced via PHPBench `#[Assert]` attributes on each benchmark method. For example:

```php
#[Assert('mode(variant.time.avg) < 100 microseconds')]
public function benchContainerInstanceResolution(): void
{
    $this->container->get(SomeService::class);
}
```

If the assertion fails, PHPBench exits with a non-zero code, and the CI job fails.

### CI Integration

The benchmark suite runs as part of the CI pipeline via:

```bash
composer bench
```

This command runs PHPBench with the configuration from `tools/php/phpbench.json` and the custom `pulsar` report generator, which outputs columns for benchmark name, subject name, parameter set, revolutions, iterations, peak memory, mode time, and relative standard deviation.

## Running Benchmarks Locally

### Full benchmark suite

```bash
composer bench
```

### Run a specific benchmark file

```bash
vendor/bin/phpbench run tests/Benchmark/ContainerBench.php --config=tools/php/phpbench.json
```

### Run with detailed report

```bash
vendor/bin/phpbench run --config=tools/php/phpbench.json --report=pulsar
```

### Run without assertions (for profiling)

```bash
vendor/bin/phpbench run --config=tools/php/phpbench.json --assert=none
```

### Compare against a baseline

```bash
# Create baseline
vendor/bin/phpbench run --config=tools/php/phpbench.json --tag=baseline

# Run comparison
vendor/bin/phpbench run --config=tools/php/phpbench.json --ref=baseline --report=pulsar
```

## How to Add New Benchmarks

### 1. Create a Benchmark Class

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

### 2. Add the Budget to performance-budgets.json

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

### 3. Verify Locally

```bash
composer bench
```

Ensure the new benchmarks pass assertions before committing.

## Budget Tuning Guide

### When to Adjust Budgets

- **Tighten** budgets after optimizing a subsystem. If container resolution consistently runs at 30us, tighten the budget from 100us to 50us to lock in the improvement.
- **Loosen** budgets only with justification. If a feature adds necessary complexity (e.g., parameter extraction adds overhead to routing), document the reason and adjust the budget proportionally.
- **Never remove** a budget. If a subsystem is deprecated, mark the budget as deprecated in the JSON file rather than deleting it.

### Tuning Process

1. Run benchmarks locally multiple times to establish a stable baseline.
2. Check the relative standard deviation (rstdev). If rstdev > 5%, the benchmark may be noisy and needs investigation before tightening.
3. Set the budget to approximately 2x the observed mode to account for CI environment variance (CI runners may be slower than development machines).
4. Update both the `#[Assert]` attribute in the benchmark file and the `max_avg` in `performance-budgets.json`.
5. Submit the change and verify that CI passes consistently across multiple runs.

### Environment Considerations

Benchmark times vary between environments. The budgets are calibrated for:

- Standard CI runners (shared CPU, no dedicated hardware).
- PHP 8.5 with OPcache enabled.
- No Xdebug or other profiling extensions loaded.

Local development machines with faster CPUs will typically run under budget. If your local results are significantly different from CI, check for:

- Xdebug loaded (`php -m | grep xdebug`).
- OPcache disabled (`php -i | grep opcache.enable`).
- Thermal throttling on laptops.
- Background processes consuming CPU.
