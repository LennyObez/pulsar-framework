# Performance Budgets

Pulsar uses a tiered benchmark system to enforce performance budgets across all request classes, crypto primitives, audit operations, and memory usage.

## Tiered Benchmark System

### Tier A - Hard Gate (Every PR)

- **Runs on**: Every pull request as a required CI check
- **Environment**: In-memory drivers only (no databases, caches, or message brokers)
- **Assertion**: Median (p50) vs absolute budget, +/- 5% tolerance
- **Failure policy**: Budget violations **block merge**
- **Covers**: Container, routing, middleware, request lifecycle, crypto (AEAD, HMAC), audit, session, validation, memory peak

### Tier B - Nightly (Realistic Environment)

- **Runs on**: Scheduled nightly (3 AM UTC) or manual trigger
- **Environment**: Real Redis, PostgreSQL, AMQP containers
- **Assertion**: p90 vs budget, +/- 15% tolerance
- **Failure policy**: Regression alerts (notification), not hard gate
- **Covers**: All Tier A benchmarks plus real-backend session, audit, and queue benchmarks

### Tier C - RC Gate (Controlled Runner)

- **Runs on**: Manually triggered before tagging an RC release
- **Environment**: Real backends on controlled runner (self-hosted or pinned instance)
- **Assertion**: p90 vs budget, +/- 10% tolerance
- **Failure policy**: Must pass before RC is tagged
- **Covers**: All Tier B benchmarks plus `crypto.kdf` (Argon2id)

## Budget Categories

### Request Class Budgets

End-to-end request lifecycle benchmarks exercising realistic middleware stacks:

| Budget Key                      | FPM Target | Persistent Target | Description                            |
| ------------------------------- | ---------- | ----------------- | -------------------------------------- |
| `request.anonymous_json_api`    | < 2 ms     | < 1 ms            | Anonymous JSON API (route + serialize) |
| `request.authenticated_session` | < 3 ms     | < 2 ms            | Session-authenticated request          |
| `request.authenticated_token`   | < 2 ms     | < 1.5 ms          | Token-authenticated request            |
| `request.with_audit`            | < 4 ms     | < 3 ms            | Request with audit trail (HMAC-signed) |
| `request.compliance_event`      | < 5 ms     | < 4 ms            | Request with compliance event dispatch |

### Security Primitive Budgets

| Budget Key                   | Target   | Tier | Description                                 |
| ---------------------------- | -------- | ---- | ------------------------------------------- |
| `crypto.encrypt.aead_1024b`  | < 100 us | A    | XChaCha20-Poly1305 AEAD, 1024-byte payload  |
| `crypto.envelope_encrypt`    | < 200 us | A    | Envelope encryption (KDF excluded)          |
| `crypto.hmac_sign`           | < 50 us  | A    | BLAKE2b keyed hash for audit signing        |
| `crypto.kdf`                 | < 10 ms  | C    | Argon2id key derivation (controlled runner) |
| `audit.log_write`            | < 500 us | A    | Single audit entry with HMAC chain          |
| `audit.chain_verify_128`     | < 1 ms   | A    | Verify 128-entry audit chain                |
| `session.load_verify.memory` | < 500 us | A    | Session load + fingerprint validation       |

### Memory Budgets

| Budget Key                  | Target | Enforcement | Description                        |
| --------------------------- | ------ | ----------- | ---------------------------------- |
| `memory.peak_anonymous`     | < 2 MB | Hard gate   | Peak memory for anonymous request  |
| `memory.peak_authenticated` | < 4 MB | Hard gate   | Peak memory for authenticated req  |
| `memory.peak_compliance`    | < 6 MB | Hard gate   | Peak memory for compliance req     |
| `allocations.anonymous`     | < 500  | Advisory    | Object allocations (anonymous)     |
| `allocations.authenticated` | < 1000 | Advisory    | Object allocations (authenticated) |

## Runtime-Specific Budgets

Separate budget files for different PHP runtimes:

- `tools/php/budgets.fpm.json` - PHP-FPM (cold bootstrap, per-request process)
- `tools/php/budgets.persistent.json` - RoadRunner/FrankenPHP (warm container, amortized bootstrap)

Persistent runtimes have lower budgets for request classes since bootstrap cost is amortized.

## Running Benchmarks Locally

```bash
# Run all benchmarks (Tier A)
composer bench

# Run benchmarks with CI output format
composer bench:ci

# Run OPcache/JIT/preload profile matrix
composer bench:profiles

# Run a single benchmark class
vendor/bin/phpbench run --config=tools/php/phpbench.json --filter=CryptoBench

# Run memory peak scenarios
php tests/Benchmark/Scenarios/memory_anonymous.php
php tests/Benchmark/Scenarios/memory_authenticated.php
php tests/Benchmark/Scenarios/memory_compliance.php
```

## Statistical Assertion Policy

All benchmark assertions use statistical methods to reduce flakiness:

- **Warmup**: N warmup iterations discarded before measurement (default: 5)
- **Measured iterations**: Minimum 50 iterations per benchmark
- **Tier A**: Assert on median (p50), +/- 5% tolerance
- **Tier B**: Assert on p90, +/- 15% tolerance
- **Tier C**: Assert on p90, +/- 10% tolerance
- **Regression detection**: Alert if p50 regresses > 5% across 3 consecutive runs

## Pipeline Manifest Governance

The file `tools/php/bench-pipeline.manifest.php` declares the exact middleware stacks and storage backends for each request-class benchmark. This manifest is content-hashed in CI - unauthorized changes fail the build.

### Changing the Manifest

1. Any change requires **Performance Engineer sign-off**
2. If the change reduces security/compliance coverage, **Architecture sign-off** is also required
3. Changes must include: measured justification, before/after data, rationale
4. Budget relaxations (raising thresholds) require additional Architecture sign-off

## Request-Class Semantic Contracts

Each request class has an explicit contract defining what the benchmark MUST exercise:

| Budget Key                      | Required Middleware                                               | Auth    | Side-Effects                 |
| ------------------------------- | ----------------------------------------------------------------- | ------- | ---------------------------- |
| `request.anonymous_json_api`    | routing, error-handler, content-negotiation                       | None    | None                         |
| `request.authenticated_session` | routing, error-handler, session-start, auth-guard, authorization  | Session | Session read/write           |
| `request.authenticated_token`   | routing, error-handler, token-resolver, auth-guard, authorization | Bearer  | Token validation             |
| `request.with_audit`            | All session + audit-writer                                        | Session | Session + audit write (HMAC) |
| `request.compliance_event`      | All audit + compliance-dispatcher                                 | Session | Session + audit + compliance |

The manifest validator checks these contracts before benchmark execution. Removing required middleware or side-effects from a request class will fail CI.

## Memory Peak Measurement

Memory peak budgets use `memory_get_peak_usage(true)` in isolated PHP processes. Each scenario runs in a fresh process to ensure accurate per-scenario peak measurement - the peak value cannot be reset within a running process.

Implementation: Each `tests/Benchmark/Scenarios/memory_*.php` script autoloads, builds the relevant request pipeline, processes one request, and outputs the peak memory in bytes.

## Adding a New Benchmark

1. Define the budget key and target in `tools/php/performance-budgets.json`
2. If runtime-specific, add overrides to `budgets.fpm.json` and `budgets.persistent.json`
3. Create the benchmark class in `tests/Benchmark/`
4. Use PHPBench attributes: `#[Subject]`, `#[Assert('mode(variant.time.avg) < BUDGET')]`
5. If it's a request class, update `bench-pipeline.manifest.php` with the semantic contract
6. For memory benchmarks, create a scenario script in `tests/Benchmark/Scenarios/`
7. Run `composer bench` to verify locally
8. All budget changes require Performance Engineer sign-off

## Budget Files

| File                                    | Purpose                               |
| --------------------------------------- | ------------------------------------- |
| `tools/php/performance-budgets.json`    | All Tier A budget definitions         |
| `tools/php/budgets.fpm.json`            | FPM-specific overrides                |
| `tools/php/budgets.persistent.json`     | Persistent-runtime overrides          |
| `tools/php/bench-pipeline.manifest.php` | Pipeline contracts and storage config |
| `tools/php/phpbench.json`               | PHPBench runner configuration         |
