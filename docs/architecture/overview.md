# Pulsar Framework — Architecture Overview

This document is the entry point for understanding the architecture of the Pulsar Framework Rust edition. It introduces the major decisions, links the six diagrams that visualise the architecture, and points at the deeper plan, ADR, and book references where each topic is fully treated.

## Five-minute summary

* **Composite architectural pattern** (per [ADR-0002](../adr/0002-modular-monolith-hexagonal.md)): modular monolith (single deployable, internal module boundaries) + formally verified microkernel (crypto + audit + session + routing + middleware + DI) + hexagonal ports-and-adapters (every crate boundary) + WASM-sandboxed extensions (third-party code in capability-scoped wasmtime instances) + event-driven inter-module communication (typed events on an internal bus, first-class at the kernel level).
* **53 first-party Rust crates** (per plan Section IV, post-consolidation per Section 16.14, post-removal per Section 16.13) organised in twelve layers, plus two non-Rust sub-projects in `services/` (admin SPA + Kubernetes Operator).
* **Strict dependency rule:** dependencies flow inward, never reverse. Enforced via `cargo deny` rulesets and mandatory PR review on Cargo.toml changes.
* **Formally verified kernel** (per [ADR-0003](../adr/0003-microkernel-formal-verification.md)): nine TLA+ specifications + Creusot function contracts where tractable.
* **Capability-based extension sandbox** (per [ADR-0004](../adr/0004-wasm-extension-sandbox.md)): every third-party extension runs in `wasmtime` with a manifest-bound capability table.

## The six diagrams

Each diagram is a single Mermaid graph plus a one-page narrative. Together they cover the full architectural surface from layered composition down to the multi-instance production deployment.

| # | Diagram | Surface | Link |
|:-:|---------|---------|------|
| 01 | Layered architecture | Twelve-layer composition + dependency rule + event bus cross-cuts + WASM sandbox at the periphery | [diagrams/01-layered.md](diagrams/01-layered.md) |
| 02 | Crate dependency graph | The 53-crate DAG, hub crates, consolidation impact, sibling-only paths | [diagrams/02-crate-dependency-graph.md](diagrams/02-crate-dependency-graph.md) |
| 03 | Request lifecycle | A representative authenticated `POST /api/v1/posts` request through every quality-relevant subsystem | [diagrams/03-request-lifecycle.md](diagrams/03-request-lifecycle.md) |
| 04 | Event bus topology | Publishers, subscribers, partitioning, delivery semantics, schema evolution | [diagrams/04-event-bus-topology.md](diagrams/04-event-bus-topology.md) |
| 05 | WASM sandbox isolation | Trust zones, capability model, crash isolation, performance budget, component-model interop | [diagrams/05-wasm-sandbox-isolation.md](diagrams/05-wasm-sandbox-isolation.md) |
| 06 | Process model | Multi-instance production deployment, stateful tier, external systems, observability flow, deployment topologies | [diagrams/06-process-model.md](diagrams/06-process-model.md) |

## Architecture decision records

The eight foundational ADRs (Sprint 0.5):

* [ADR-0001](../adr/0001-rewrite-in-rust.md) — Full rewrite of Pulsar Framework from PHP 8.5 to Rust 1.95+
* [ADR-0002](../adr/0002-modular-monolith-hexagonal.md) — Modular monolith with hexagonal ports-and-adapters
* [ADR-0003](../adr/0003-microkernel-formal-verification.md) — Formally verified microkernel
* [ADR-0004](../adr/0004-wasm-extension-sandbox.md) — WebAssembly extension sandbox with capability-based security
* [ADR-0005](../adr/0005-apache-2-0-licence.md) — Apache License, Version 2.0 with EUIPO trademark protection
* [ADR-0006](../adr/0006-crates-io-pulsar-namespace.md) — Crates.io `pulsar-*` namespace with meta-crate
* [ADR-0007](../adr/0007-branch-model.md) — Branch model with `main` / `develop` / `feat/sprint-N-M-topic`
* [ADR-0008](../adr/0008-gitflow-sprint-branches.md) — Sprint feature branch contract with squash-merge

Full index + roadmap of ~85 future ADRs: [docs/adr/INDEX.md](../adr/INDEX.md).

## Plan references

* **Section I executive summary** — high-level goals, metrics, and the eleven-phase plan.
* **Section II Strategic Decisions** — the fifty locked decisions every other artefact derives from.
* **Section III Architecture Overview** — full prose treatment of the seven-layer architecture (now twelve layers post-consolidation), dependency rule, hexagonal pattern, microkernel composition, WASM sandbox, and event bus.
* **Section IV Workspace Layout** — the 53-crate enumeration with per-crate purpose, key types, dependencies, re-export-in-meta status, PHP parity, and sprint of delivery.
* **Section V Phased Plan** — eleven-phase sprint sequence with deliverables and exit criteria.
* **Section VI Quality Gates** — the per-sprint gate command sequence (fmt, clippy, check, nextest, llvm-cov, mutants, fuzz, deny, audit, machete, bench, creusot, tlc, ADR delivery, changelog).
* **Section XII Success Metrics for 1.0.0 GA** — the quantitative bar.
* **Section XV PHP-to-Rust parity matrix** — every PHP module mapped to its Rust counterpart with sprint reference and parity status.
* **Section XVI Parity Gap Closure — State-of-Art Extensions** — the additions beyond strict PHP parity (eIDAS 2 EUDI Wallet, FAPI 2.0, PSD2 SCA, NIST AI RMF, OWASP LLM Top 10, OpenSSF Scorecard, CIS Benchmarks, STIG, etc.).

## Beyond this overview

* The **mdBook** (lands at Sprint 4.5 documentation consolidation) covers each subsystem in narrative depth: Getting Started, Concepts, Guide, Cookbook, API Reference. Source in `docs/book/src/`.
* **TLA+ specifications** (nine specs per plan Section XII) live in `spec/` and are the formal source of truth for kernel + protocol invariants.
* **Compliance matrix** in `docs/compliance/matrix.md` (lands progressively across Phase 2A) maps the twenty-three regulatory framework clauses to Pulsar features, extensions, and controls.
* **Observability dashboards** in `docs/observability/dashboards/` (twenty Grafana dashboards at GA per Section XII) cover every subsystem.
* **Threat model** in `docs/security/threat-model.md` (lands at Sprint 1.5 + 2.5 progressively) walks the STRIDE surface across the layers.

## Sprint 0.6 deliverable

This file plus the six diagrams constitute the Sprint 0.6 architecture-diagrams deliverable per plan Section V. Exit criteria:

* ✅ Six diagrams render in GitHub Mermaid preview (verified during commit by the GitHub Mermaid renderer; locally via `mermaid-cli` if installed).
* ✅ This `docs/architecture/overview.md` linked from `README.md` and `docs/plan.md`.
