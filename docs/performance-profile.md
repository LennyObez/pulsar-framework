# Performance Profile

Memory and performance budgets for the Pulsar framework. All benchmarks run via PHPBench with statistical sampling (5 iterations, 1000 revolutions, 1 warmup cycle).

## Memory Budgets

| Operation                | Budget   | Measurement tool          |
| ------------------------ | -------- | ------------------------- |
| Route registration (200) | < 512 KB | `memory_get_usage(true)`  |
| Route matching (100x)    | < 256 KB | `memory_get_usage(true)`  |
| Container (100 services) | < 256 KB | `memory_get_usage(true)`  |
| Entity hydration (1000)  | < 1 MB   | `memory_get_usage(true)`  |
| JSON response (100x)     | < 512 KB | `memory_get_usage(true)`  |
| Full request cycle       | < 2 MB   | `memory_get_peak_usage()` |
| Anonymous API request    | < 2 MB   | Fresh process peak        |
| Authenticated request    | < 4 MB   | Fresh process peak        |
| Compliance request       | < 6 MB   | Fresh process peak        |

## ORM Query Builder

| Operation               | Budget   |
| ----------------------- | -------- |
| Simple SELECT           | < 50 us  |
| Complex SELECT (5 cond) | < 100 us |
| SELECT with JOIN        | < 100 us |
| SELECT with LIKE        | < 50 us  |
| INSERT build            | < 50 us  |
| UPDATE build            | < 50 us  |
| DELETE build            | < 50 us  |
| 10 sequential SELECTs   | < 500 us |

## ORM Hydration

| Operation           | Budget   |
| ------------------- | -------- |
| Single entity       | < 20 us  |
| 10 entities         | < 100 us |
| 100 entities        | < 1 ms   |
| 1000 entities       | < 10 ms  |
| Dehydrate (INSERT)  | < 20 us  |
| Dehydrate (UPDATE)  | < 20 us  |
| Extract primary key | < 10 us  |

## ORM Relations

| Operation                | Budget  |
| ------------------------ | ------- |
| Eager-load HasMany (50p) | < 10 ms |
| withCount (50 parents)   | < 5 ms  |
| Batch-load 500 IDs       | < 10 ms |
| FetchPlan create+merge   | < 10 us |
| Nested FetchPlan         | < 10 us |
| Empty relation load      | < 5 us  |
| No-op FetchPlan          | < 5 us  |

## Routing

| Operation                  | Budget   |
| -------------------------- | -------- |
| Small router (10, first)   | < 5 us   |
| Small router (10, last)    | < 5 us   |
| Medium router (50, middle) | < 50 us  |
| Large router (200, last)   | < 100 us |
| Parameterized (3 segments) | < 200 us |
| Route compilation (150)    | < varies |
| URL generation (named)     | < 50 us  |

## Running Benchmarks

### PHPBench (statistical, repeatable)

```bash
vendor/bin/phpbench run tests/Benchmark/ --config=tools/php/phpbench.json --report=pulsar
```

### ORM benchmarks (standalone)

```bash
vendor/bin/phpbench run benchmarks/Orm/ --config=tools/php/phpbench.json --report=pulsar
```

### Memory profiling

```bash
vendor/bin/phpbench run benchmarks/Memory/ --config=tools/php/phpbench.json --report=pulsar
```

### Comparative benchmarks (quick, non-PHPBench)

```bash
php benchmarks/Comparative/PulsarBench.php --iterations=10000 --json
```

## Methodology

- **Timing**: PHPBench measures wall-clock time with statistical controls (retry threshold, standard deviation reporting). Assertions use `mode(variant.time.avg)` for robustness against outliers.
- **Memory**: `memory_get_usage(true)` for allocation tracking (includes allocator slack). `memory_get_peak_usage(true)` for peak measurement in isolated processes.
- **Budgets**: Set at 2-5x observed typical performance to allow for CI variance while catching regressions. Tighten as the codebase stabilizes.
- **Environment**: PHP 8.5, opcache enabled, no Xdebug, no PCOV. CI runs on GitHub-hosted runners (Ubuntu, 2-core).
