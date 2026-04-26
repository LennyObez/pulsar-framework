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

## Roadmap

ADRs scheduled for issuance during subsequent sprints (per plan Section V references and Section XV.4 PHP ADR carry-over):

* **ADR-0009** Crypto Primitive Selection — Sprint 1.1
* **ADR-0010** Audit HMAC Chain — Sprint 1.2
* **ADR-0011** Session State Machine — Sprint 1.3
* **ADR-0012** Router Trie Design — Sprint 1.4
* **ADR-0013** Middleware Pipeline Ordering — Sprint 1.5
* **ADR-0014** DI Container Design — Sprint 1.6
* **ADR-0015** HTTP Framework on hyper — Sprint 2.1
* **ADR-0016** Custom Template Engine — Sprint 2.2
* **ADR-0017** ORM Nineteen Attributes (extended) — Sprint 2.3
* **ADR-0018** Audit Extension Persistence — Sprint 2.4
* **ADR-0019** Authentication Suite (extended scope) — Sprint 2.5
* **ADR-0020** Compliance Mapping Matrix (extended scope) — Sprint 2.6
* **ADR-0021** Observability Stack (extended) — Sprint 2.7
* **ADR-0022** Native Admin Stack — Sprint 3.1
* **ADR-0023** CMS Architecture — Sprint 3.2
* **ADR-0024** Forum Extension — Sprint 3.3
* **ADR-0025** Payments Extension — Sprint 3.4
* **ADR-0026** WASM Extension Sandbox (implementation) — Sprint 4.1
* **ADR-0027** CLI Design — Sprint 4.2
* **ADR-0028** Extension Marketplace — Sprint 4.3
* **ADR-0029** ADR-inversion of PHP ADR-0005 (synchronous core → tokio async) — Sprint 2A start
* **ADR-0030** through **ADR-0035** — `pulsar-guard` sub-modules (csrf, sri, incident, ratelimit, resilience, ssrf) per Sprint 1.5 deliverables
* **ADR-0036** Configuration Layers — Sprint 2B.1
* **ADR-0037** Multi-Tier Cache — Sprint 2B.2
* **ADR-0038** through **ADR-0049** — application infrastructure crates (storage through tenancy) per Phase 2B
* **ADR-0050** Consent Management — Sprint 2C.1
* **ADR-0051** Data Protection Runtime — Sprint 2C.2
* **ADR-0052** HSM Integration — Sprint 2C.3
* **ADR-0053** REST API Framework — Sprint 3B.1
* **ADR-0054** WebSocket Dispatch — Sprint 3B.2 (within consolidated `pulsar-realtime`)
* **ADR-0055** Broadcasting — Sprint 3B.2 (within consolidated `pulsar-realtime`)
* **ADR-0056** GraphQL Adapter — Sprint 3B.3
* **ADR-0057** gRPC Adapter — Sprint 3B.4
* **ADR-0058** MCP Server — Sprint 3B.5
* **ADR-0059** AI Provider Abstraction — Sprint 3C.1
* **ADR-0060** Vector Search — Sprint 3C.2
* **ADR-0061** AI Governance Runtime — Sprint 3C.3
* **ADR-0062** Reactive Framework — Sprint 3D.1
* **ADR-0063** Dev Studio — Sprint 3D.2
* **ADR-0064** Analytics Subsystem — Sprint 3D.3
* **ADR-0065** Accessibility Tooling — Sprint 3D.4
* **ADR-0066** Workflow Engine — Sprint 3E.1 (within consolidated `pulsar-orchestration`)
* **ADR-0067** Saga Orchestration — Sprint 3E.1 (within consolidated `pulsar-orchestration`)
* **ADR-0068** through **ADR-0075** — domain extensions per Phase 3E (some retired per Section 16.13; downstream-application-only for tickets/feedback/booking/devices/releases/importexport)
* **ADR-0076** Health Supervisor — Sprint 3E.4 (within consolidated `pulsar-cluster`)
* **ADR-0077** Service Discovery — Sprint 3E.4 (within consolidated `pulsar-cluster`)
* **ADR-0078** Deploy Orchestration — Sprint 3E.5
* **ADR-0079** Enterprise SSO (SAML + LDAP + Kerberos) — Sprint 2.5
* **ADR-0080** Continuous Evaluation (RISC + CAEP) — Sprint 2.5
* **ADR-0081** Verifiable Credentials + DIDs — Sprint 2C `pulsar-identity-standards`
* **ADR-0082** Multi-backend Database Support — Sprint 2.3
* **ADR-0083** CDC and Replication Primitives — Sprint 2.3
* **ADR-0084** Regulatory Reporting Automation — Sprint 2.6
* **ADR-0085** OpenTelemetry Semantic Conventions Compliance — Sprint 2.7
* **ADR-0086** Continuous Profiling — Sprint 2.7
* **ADR-0087** AsyncAPI Emission — Sprint 3B.1
* **ADR-0088** OData Feature Flag — Sprint 3B.1
* **ADR-0089** Server-Sent Events — Sprint 3B.2 (within consolidated `pulsar-realtime`)
* **ADR-0090** WebTransport — Sprint 3B.2 (within consolidated `pulsar-realtime`)
* **ADR-0091** NIST AI RMF Integration — Sprint 3C.3
* **ADR-0092** OWASP LLM Top 10 Compliance — Sprint 3C.3
* **ADR-0093** Agentic Framework — Sprint 3C.4
* Plus per-sprint ADRs not yet enumerated (ADR-0094+) for state-of-art extensions per plan Section XIV and Section XVI.

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
