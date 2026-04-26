# Observability dashboards

> **Status (2026-04-25, Phase 0).** Empty by design. Section XII success
> metrics call for **20 production-ready dashboards at GA** covering each
> framework subsystem. The directory and README land now so dashboard
> JSON exports have a stable home as subsystems start emitting metrics
> in Phase 1+.

## Purpose

Pulsar exports OpenTelemetry-native metrics, traces, and logs from every
crate (Section 16.5 + the `pulsar-observability` crate). The committed
dashboard JSON exports give operators a turnkey set of Grafana boards
that visualise the canonical signals — RED (Rate / Errors / Duration)
plus USE (Utilisation / Saturation / Errors) plus subsystem-specific
business metrics.

Operators import the boards into self-hosted Grafana via:

```bash
grafana-cli admin import-json --uid pulsar-http docs/observability/dashboards/http.json
```

…or via Grafana provisioning (file-based dashboard discovery).

## Dashboard catalogue (target shape — populated from Sprint 1.1+)

The 20 dashboards line up with Section 16.5.4 observability acceptance
criteria. Naming convention: `<crate-suffix>.json` (e.g. `kernel.json`
for `pulsar-kernel`).

### Layer 1 — Kernel + Security (3)

| File | Subsystem | Headline panels |
|------|-----------|------------------|
| `kernel.json` | `pulsar-kernel` | AEAD ops/sec, Argon2id derivation latency p99, rng entropy depth, capability-token lifecycle. |
| `guard.json` | `pulsar-guard` | CSRF reject rate, SRI mismatch rate, ratelimit shed %, SSRF block events. |
| `audit.json` | `pulsar-audit` | Audit-log throughput, hash-chain integrity, append failures, retention burn-down. |

### Layer 2 — Core foundation (5)

| File | Subsystem | Headline panels |
|------|-----------|------------------|
| `http.json` | `pulsar-http` | RED per-route, p50/p95/p99/p999 latency, TLS handshake duration, connection-pool saturation. |
| `engine.json` | `pulsar-engine` | Render time per template, cache hit ratio, partials-fan-out depth. |
| `orm.json` | `pulsar-orm` | Query duration histogram, prepared-statement cache hit ratio, connection-pool saturation, slow-query log. |
| `auth.json` | `pulsar-auth` | Login attempts, MFA challenge duration, session creation rate, expired-token rejections. |
| `compliance.json` | `pulsar-compliance` | Compliance-control-point status, framework coverage matrix, exception expirations. |

### Layer 3 — Data protection (2)

| File | Subsystem | Headline panels |
|------|-----------|------------------|
| `dataprotection.json` | `pulsar-dataprotection` | RtbF queue depth, two-phase-commit duration, redaction ops/sec, encryption-at-rest verification. |
| `consent.json` | `pulsar-consent` | Active consent records, withdrawal events, consent-purpose distribution, retention timer accuracy. |

### Layer 4 — Application infrastructure (4)

| File | Subsystem | Headline panels |
|------|-----------|------------------|
| `queue.json` | `pulsar-queue` | Enqueue/dequeue rate, queue depth, retry distribution, dead-letter migration. |
| `scheduler.json` | `pulsar-scheduler` | Job firings, drift from scheduled time, missed-firing detection. |
| `cache.json` | `pulsar-cache` | Hit ratio, eviction rate, key-cardinality estimate, lock-contention. |
| `mail.json` | `pulsar-mail` | Send rate, bounce ratio, DKIM/DMARC failure events, queue burn-down. |

### Layer 5 — API paradigms (3)

| File | Subsystem | Headline panels |
|------|-----------|------------------|
| `api.json` | `pulsar-api` (REST) | RED per-resource, OpenAPI coverage, deprecation warnings emitted. |
| `graphql.json` | `pulsar-graphql` | Operation cost histogram, persisted-query cache ratio, dataloader N+1 detector. |
| `realtime.json` | `pulsar-realtime` | Active websocket connections, message broadcast latency, backpressure shed events. |

### Layer 6 — AI surface (2)

| File | Subsystem | Headline panels |
|------|-----------|------------------|
| `ai.json` | `pulsar-ai` | Inference latency, token throughput, provider availability, prompt-template hit ratio. |
| `ai-governance.json` | `pulsar-ai-governance` | Policy-evaluation latency, audit-record creation, model-card freshness. |

### Layer 7 — Reactive + dev experience (1)

| File | Subsystem | Headline panels |
|------|-----------|------------------|
| `live.json` | `pulsar-live` | LiveView session count, server-push latency, reconnect rate. |

## Required panels per dashboard

Every dashboard MUST include:

1. **Header row** — service version, build commit, deployment region, last-deploy timestamp.
2. **Saturation row** — CPU%, memory%, network throughput, disk I/O.
3. **Error row** — error rate (4xx + 5xx), error log volume, panic counter.
4. **Latency row** — p50 / p95 / p99 / p999 with 1m / 5m / 1h windows.
5. **Throughput row** — request rate, successful operations / sec.
6. **Subsystem-specific row** — at least three panels for crate-specific signals.
7. **Annotations** — deploy markers, incident timestamps, feature-flag toggles.
8. **Alert pointer** — link to the corresponding `pulsar-runbooks` entry.

## Data sources

All dashboards reference Prometheus + Tempo + Loki. The data-source UIDs
are template variables (`$datasource`) so importers can rebind them to
their own infrastructure:

```jsonc
"templating": {
  "list": [
    { "name": "datasource", "type": "datasource", "query": "prometheus" },
    { "name": "tempo",      "type": "datasource", "query": "tempo"     },
    { "name": "loki",       "type": "datasource", "query": "loki"      }
  ]
}
```

## Validation pipeline

`docs/observability/dashboards/validate.sh` (Sprint 1.0 deliverable) runs
on every PR and:

1. Confirms each `*.json` is parseable JSON.
2. Confirms each dashboard declares the seven required rows.
3. Confirms each Prometheus metric reference matches a known-emitted
   series in `docs/observability/metric-catalogue.md` — guards against
   dashboards that silently break when a metric is renamed or removed.
4. Validates against the Grafana dashboard JSON schema published with
   the LTS Grafana release referenced in `Cargo.toml` `[pulsar.observability.grafana_lts_version]`.

## Naming + versioning

- File name matches the crate without the `pulsar-` prefix
  (`pulsar-http` → `http.json`).
- Dashboard `uid` is `pulsar-<crate-suffix>` (stable across releases — operators rely on UIDs to update existing boards in place).
- Dashboard `version` increments on every committed change; a `changelog`
  panel at the bottom of every board records meaningful diffs.

## Policy

- **No proprietary data sources.** Every dashboard renders against vendor-neutral
  Prometheus + Tempo + Loki. Vendor-specific exporters land in `docs/observability/dashboards/vendor/<vendor>/`.
- **No hard-coded URLs.** All links use template variables.
- **No custom-built panel plugins.** Only built-in Grafana panel types.
- **No PII.** Dashboards must not surface query terms, request bodies,
  or any user-attributable data — only aggregated counters, histograms,
  and trace spans.
