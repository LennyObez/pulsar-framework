# Performance Benchmarking

Pulsar includes a benchmark harness that measures the performance impact of OPcache, JIT, and preloading configurations.

## Running Benchmarks

```bash
php tools/bench/run.php
```

The harness spawns fresh PHP processes for each configuration profile, ensuring isolated measurements. Results are written atomically to `var/bench/results.json` with stable key order for clean diffs.

### Custom Output Path

```bash
php tools/bench/run.php --output=path/to/results.json
```

## What Is Measured

| Metric    | Description                                                |
| --------- | ---------------------------------------------------------- |
| Boot (us) | Cold-boot time — kernel creation, route registration, boot |
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

## Interpreting Results

- **p50 vs p95**: The p50 (median) shows typical performance. The p95 shows worst-case latency. A large gap indicates occasional slow requests (GC pauses, JIT compilation, etc.).
- **RSS**: Peak memory usage. Preloading increases baseline RSS since classes are loaded into shared memory at startup.
- **RPS**: Throughput estimate. Higher is better. This is computed from the total measured time, not wall-clock time.
- **Boot time**: Primarily useful for CLI tools and serverless environments where cold-start matters.

## Caveats

- **CLI vs FPM**: CLI SAPI results may differ significantly from FPM. JIT warmup behavior and memory allocation differ between SAPIs. Always validate with your production SAPI.
- **JIT warmup**: JIT benefits increase after warmup iterations. The harness runs 200 warmup iterations before measuring.
- **Workload dependency**: JIT benefits vary by workload. CPU-intensive operations benefit most; I/O-bound workloads may see little difference.
- **System noise**: Run benchmarks on a quiet system for reproducible results. Close other applications and avoid running during background tasks.

## Temporary Preload Files

The benchmark harness generates temporary preload scripts solely for benchmarking. These are cleaned up after the run. For production, use the `preload:dump` command to create an immutable build artifact:

```bash
php bin/pulsar preload:dump --output=preload.generated.php
```

See [`docs/DEPLOYMENT.md`](DEPLOYMENT.md) for production configuration.

## Results File Format

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
