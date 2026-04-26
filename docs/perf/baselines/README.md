# Performance baselines

> **Status (2026-04-25, Phase 0).** Empty by design. No criterion benchmarks
> exist yet because no Phase 1 crate has shipped instrumented logic. The
> directory and README land now so that `benchmark.yml` has a stable target
> for archived comparison artefacts and so the regression-detection pipeline
> is wired end-to-end before the first measurable workload exists.

## Purpose

Pulsar holds itself to a hard performance contract per Decision 2.27 and
Section 16.10.6 (resource budgets). Every MINOR release records a baseline
for every named benchmark, and every PR runs `cargo bench` and compares
against the previously stored baseline. A regression beyond the **5% slowdown
threshold** fails the PR gate; a tightening beyond the same threshold lands
as a release-note highlight.

## Layout (target shape — populated from Sprint 1.1 onward)

```
docs/perf/baselines/
├── README.md                          (this file)
├── v0.1.0/
│   ├── pulsar-kernel.json             # criterion --message-format=json archive
│   ├── pulsar-http.json
│   ├── pulsar-engine.json
│   └── …
├── v0.2.0/
│   └── …
└── current.json                       # symlink (or copy) of the active baseline
```

Each baseline file is the raw criterion JSON archive (`target/criterion/<bench>/base/estimates.json`
flattened). The archive captures point estimates, confidence intervals,
and median absolute deviations — enough information for a downstream PR
comparison without re-running the canonical baseline.

## Benchmark catalogue (planned)

The ten subsystems with shipped Phase 1+ benchmarks:

| Subsystem | Bench file | Workload class |
|-----------|------------|----------------|
| `pulsar-kernel` | `crypto_aead.rs` | AES-256-GCM-SIV encrypt/decrypt 4 KiB / 1 MiB / 16 MiB. |
| `pulsar-kernel` | `crypto_kdf.rs` | Argon2id parameter sweep. |
| `pulsar-http` | `router.rs` | Radix-trie lookup, 1k / 10k / 100k routes. |
| `pulsar-http` | `middleware_chain.rs` | Empty / 5-step / 20-step pipeline. |
| `pulsar-engine` | `template_render.rs` | Hot-path render 1 KiB / 16 KiB / 256 KiB templates. |
| `pulsar-orm` | `query_compile.rs` | DSL → SQL with prepared-statement cache hit/miss. |
| `pulsar-realtime` | `websocket_dispatch.rs` | Inbound message dispatch under contention. |
| `pulsar-search` | `tantivy_index.rs` | Document ingest + query latency. |
| `pulsar-cache` | `lru.rs` | Get/put under varying key cardinalities. |
| `pulsar-queue` | `enqueue_dequeue.rs` | Single-producer multi-consumer scheduling. |

Additional benches may be added as subsystems mature, but each one MUST
be added with a baseline within the same PR — never "land bench, baseline next sprint".

## Regression-detection pipeline

`benchmark.yml` runs on every push to `develop` and on PR creation:

1. Run `cargo bench --workspace --message-format=json` and capture the
   `bench` lines.
2. Parse `mean.point_estimate` per benchmark.
3. Load `docs/perf/baselines/current.json` for the corresponding benchmark.
4. Compute relative delta `(new - baseline) / baseline`.
5. **Fail** if any benchmark exceeds `+5%` (slower).
6. **Annotate** if any benchmark improves beyond `-5%` (faster).

The 5% threshold is configurable per `[pulsar.bench]` table in `Cargo.toml`
once Sprint 1.0 lands the workspace metadata schema.

## Hardware caveat

GitHub-hosted `ubuntu-latest` runners exhibit ±10% variance between job
invocations on identical workloads. The 5% threshold therefore relies on
running benches on a **dedicated self-hosted runner** introduced in
Sprint 0.10 (commit forthcoming). Until then, baselines collected on
shared runners are recorded as `confidence: low` and the threshold is
relaxed to ±20% — a coarse guardrail, not a precision instrument.

## Updating a baseline

When a measured improvement is intentional (algorithmic change, dependency
upgrade, hardware refresh), the maintainer regenerates the baseline:

```bash
cargo bench --workspace --message-format=json > /tmp/bench.json
./tools/scripts/freeze-bench-baseline.sh v0.X.0 /tmp/bench.json
```

The script writes per-crate JSON files under `docs/perf/baselines/v0.X.0/`
and updates the `current.json` pointer. The PR description must include
the rationale for the new baseline (commit reference + measured delta).

## Policy

- **No silent regressions.** A 5% slowdown fails CI.
- **No silent improvements.** A 5% speedup is acknowledged in release notes.
- **No baseline rebase.** Once a baseline is committed it is immutable;
  improvements are recorded as new baselines, not as overwrites.
- **No platform mixing.** Linux glibc is the canonical baseline target.
  macOS / Windows / musl baselines are tracked separately when they exist.
