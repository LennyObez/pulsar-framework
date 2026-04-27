# Architecture Decision Records — Index

This index lists every Architecture Decision Record (ADR) in the Pulsar Framework Rust edition, ordered by ADR number. Status legend: **A** Accepted, **D** Deprecated, **S** Superseded, **P** Proposed.

The ADR template lives at [`0000-template.md`](0000-template.md). Every ADR follows that structure (Status, Context, Decision, Consequences, Alternatives considered, References, optional Compliance mapping).

Per plan Section XII success metrics, **at least sixty ADRs** are merged before 1.0.0 GA. The eight ADRs landed in Sprint 0.5 are the foundational decisions that the rest of the rewrite builds on.

## Index

| ADR | Title | Status | Date | Sprint | Section II decision(s) | Supersedes |
|-----|-------|:------:|------|--------|------------------------|------------|
| [ADR-0001](0001-rewrite-in-rust.md) | Full rewrite of Pulsar Framework from PHP 8.5 to Rust 1.95+ | A | 2026-04-26 | 0.5 | 2.1, 2.2, 2.3, 2.7 | — |
| [ADR-0002](0002-modular-monolith-hexagonal.md) | Modular monolith with hexagonal ports-and-adapters across crate boundaries | A | 2026-04-26 | 0.5 | 2.22, 2.32 | — |
| [ADR-0003](0003-microkernel-formal-verification.md) | Formally verified microkernel for crypto, audit, session, router, middleware, DI | A | 2026-04-26 | 0.5 | 2.20, 2.22, 2.3 | — |
| [ADR-0004](0004-wasm-extension-sandbox.md) | WebAssembly extension sandbox with capability-based security | A | 2026-04-26 | 0.5 | 2.19 | — |
| [ADR-0005](0005-apache-2-0-licence.md) | Apache License, Version 2.0 with EUIPO trademark protection | A | 2026-04-26 | 0.5 | 2.21, 2.50 | — |
| [ADR-0006](0006-crates-io-pulsar-namespace.md) | Crates.io `pulsar-*` namespace with meta-crate `pulsar-framework` | A | 2026-04-26 | 0.5 | 2.23, 2.24 | — |
| [ADR-0007](0007-branch-model.md) | Branch model with `main` stable, `develop` integration, sprint feature branches | A | 2026-04-26 | 0.5 | 2.5, 2.6 | — |
| [ADR-0008](0008-gitflow-sprint-branches.md) | Sprint feature branch contract — `feat/sprint-N-M-topic` with squash-merge | A | 2026-04-26 | 0.5 | 2.5, 2.6, 2.30 | — |
| [ADR-0009](0009-hacl-star-crypto-primitives.md) | HACL\* via FFI for cryptographic primitives in `pulsar-kernel` | A | 2026-04-27 | 0.9-bis | 2.53, 2.20, 2.14 | extends 2.14 |
| [ADR-0010](0010-spark-2014-invariants-module.md) | Ada/SPARK 2014 module for capability + audit-chain invariants | A | 2026-04-27 | 0.9-bis | 2.54, 2.20, 2.46 | — |
| [ADR-0011](0011-de-fusion-and-sqlx-pattern-split.md) | Dé-fusion of v2.2 meta-crates + sqlx-pattern driver split → 76 crates | A | 2026-04-27 | 0.9-bis | 2.51, 2.32, 2.22 | Section 16.14 v2.2 consolidation |
| [ADR-0012](0012-hybrid-pqc-from-sprint-1-1.md) | Hybrid PQC (X25519 + ML-KEM-768 / Ed25519 + ML-DSA-65) from Sprint 1.1 | A | 2026-04-27 | 0.9-bis | 2.58, 2.53, 2.20 | Section 14.1 v2.2 phasing |
| [ADR-0013](0013-audit-chain-ed25519-merkle-transparency-log.md) | Audit chain primitive — Ed25519 + Merkle tree + RFC 6962 transparency log | A | 2026-04-27 | 0.9-bis | 2.55, 2.20, 2.54, 2.31 | Section 16.5 v2.2 audit-chain |
| [ADR-0014](0014-mcdc-coverage-and-mutation-kill-rate.md) | 100% line + branch + MC/DC coverage + 99% mutation kill rate on critical-tier crates | A | 2026-04-27 | 0.9-bis | 2.56, 2.28, 2.20 | Section VI v2.2 mutation threshold |

## Roadmap

ADRs scheduled for issuance during subsequent sprints (per plan Section V references and Section XV.4 PHP ADR carry-over). The numbering shifted by 6 to accommodate the v2.3 reconciliation ADRs (0009-0014 above):

* **ADR-0015** Audit Subsystem Implementation — Sprint 1.2 (consumes ADR-0013 audit-chain primitive)
* **ADR-0016** Session State Machine — Sprint 1.3
* **ADR-0017** Router Trie Design — Sprint 1.4
* **ADR-0018** Middleware Pipeline Ordering — Sprint 1.5
* **ADR-0019** DI Container Design — Sprint 1.6
* **ADR-0020** HTTP Framework on hyper — Sprint 2.1
* **ADR-0021** Custom Template Engine — Sprint 2.2
* **ADR-0022** ORM Architecture (multi-driver, sqlx-pattern per ADR-0011) — Sprint 2.3
* **ADR-0023** Audit Extension Persistence — Sprint 2.4
* **ADR-0024** Authentication Suite (extended scope) — Sprint 2.5
* **ADR-0025** Compliance Mapping Matrix (extended scope) — Sprint 2.6
* **ADR-0026** Observability Stack (extended) — Sprint 2.7
* **ADR-0027** Native Admin Stack — Sprint 3.1
* **ADR-0028** CMS Architecture — Sprint 3.2
* **ADR-0029** Forum Extension — Sprint 3.3
* **ADR-0030** Payments Extension — Sprint 3.4
* **ADR-0031** WASM Extension Sandbox (implementation) — Sprint 4.1
* **ADR-0032** CLI Design — Sprint 4.2
* **ADR-0033** Extension Marketplace — Sprint 4.3
* **ADR-0034** ADR-inversion of PHP ADR-0005 (synchronous core → tokio async) — Sprint 2A start
* **ADR-0035** through **ADR-0040** — security-controls sub-modules (csrf, sri, incident, ratelimit, resilience, ssrf-guard) per Sprint 1.5 deliverables (now per ADR-0011 dé-fusion: each gets its own ADR)
* **ADR-0041** Configuration Layers — Sprint 2B.1
* **ADR-0042** Multi-Tier Cache — Sprint 2B.2
* **ADR-0043** through **ADR-0054** — application infrastructure crates (storage through tenancy) per Phase 2B
* **ADR-0055** Consent Management — Sprint 2C.1
* **ADR-0056** Data Protection Runtime — Sprint 2C.2
* **ADR-0057** HSM Integration — Sprint 2C.3
* **ADR-0058** REST API Framework — Sprint 3B.1
* **ADR-0059** WebSocket Dispatch — Sprint 3B.2 (per ADR-0011 dé-fusion: own crate `pulsar-websocket`)
* **ADR-0060** Broadcasting — Sprint 3B.2 (per ADR-0011 dé-fusion: own crate `pulsar-broadcasting`)
* **ADR-0061** GraphQL Adapter — Sprint 3B.3
* **ADR-0062** gRPC Adapter — Sprint 3B.4
* **ADR-0063** MCP Server — Sprint 3B.5
* **ADR-0064** AI Provider Abstraction — Sprint 3C.1
* **ADR-0065** Vector Search — Sprint 3C.2
* **ADR-0066** AI Governance Runtime — Sprint 3C.3
* **ADR-0067** Reactive Framework — Sprint 3D.1
* **ADR-0068** Dev Studio — Sprint 3D.2
* **ADR-0069** Analytics Subsystem — Sprint 3D.3
* **ADR-0070** Accessibility Tooling — Sprint 3D.4
* **ADR-0071** Workflow Engine — Sprint 3E.1 (per ADR-0011 dé-fusion: own crate `pulsar-workflow`)
* **ADR-0072** Saga Orchestration — Sprint 3E.1 (per ADR-0011 dé-fusion: own crate `pulsar-saga`)
* **ADR-0073** through **ADR-0080** — domain extensions per Phase 3E (some retired per Section 16.13; downstream-application-only for tickets/feedback/booking/devices/releases/importexport)
* **ADR-0081** Health Supervisor — Sprint 3E.4 (per ADR-0011 dé-fusion: own crate `pulsar-supervisor`)
* **ADR-0082** Service Discovery — Sprint 3E.4 (per ADR-0011 dé-fusion: own crate `pulsar-service-discovery`)
* **ADR-0083** Deploy Orchestration — Sprint 3E.5
* **ADR-0084** Enterprise SSO (SAML + LDAP + Kerberos) — Sprint 2.5
* **ADR-0085** Continuous Evaluation (RISC + CAEP) — Sprint 2.5
* **ADR-0086** Verifiable Credentials + DIDs — Sprint 2C `pulsar-identity-standards`
* **ADR-0087** Multi-backend Database Support (per ADR-0011 sqlx-pattern) — Sprint 2.3
* **ADR-0088** CDC and Replication Primitives — Sprint 2.3
* **ADR-0089** Regulatory Reporting Automation — Sprint 2.6
* **ADR-0090** OpenTelemetry Semantic Conventions Compliance — Sprint 2.7
* **ADR-0091** Continuous Profiling — Sprint 2.7
* **ADR-0092** AsyncAPI Emission — Sprint 3B.1
* **ADR-0093** OData Feature Flag — Sprint 3B.1
* **ADR-0094** Server-Sent Events — Sprint 3B.2 (per ADR-0011 dé-fusion: own crate `pulsar-sse`)
* **ADR-0095** WebTransport — Sprint 3B.2 (per ADR-0011 dé-fusion: own crate `pulsar-webtransport`)
* **ADR-0096** NIST AI RMF Integration — Sprint 3C.3
* **ADR-0097** OWASP LLM Top 10 Compliance — Sprint 3C.3
* **ADR-0098** Agentic Framework — Sprint 3C.4
* **ADR-0099** SLSA Source L3 Implementation — Sprint 0.10 (consumes ADR-0007 + Decision 2.57)
* **ADR-0100** in-toto Layout Implementation — Sprint 0.10 (consumes Decision 2.57)
* Plus per-sprint ADRs not yet enumerated (ADR-0101+) for state-of-art extensions per plan Section XIV and Section XVI.

## Conventions

* **Numbering:** monotonically increasing four-digit identifier; never re-used.
* **Filename:** `<NNNN>-<kebab-title>.md`.
* **Status transitions:** Proposed → Accepted (most common) → Deprecated → Superseded by ADR-XXXX.
* **Supersedes chain:** when an ADR supersedes a prior decision, the superseded ADR's status changes to Superseded and the new ADR's `Supersedes:` field references it. Both files remain in the index.
* **CI invariant:** `ci / adr-index` job verifies every file referenced in this index exists in `docs/adr/`. Adding an ADR without indexing it (or indexing without the file) blocks merge.
* **Naming + writing conventions:** plan Section XVII applies; deviations from XVII require an ADR.

## See also

* Plan Section II Strategic Decisions (the locked decisions ADRs derive from): [`docs/plan.md`](../plan.md)
* Plan Section XV.4 PHP ADR carry-over (mapping from the thirty PHP ADRs to Rust ADRs): [`docs/plan.md`](../plan.md)
* CONTRIBUTING.md (contributor flow that produces ADRs): [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md)
