# Introduction

> **Status (2026-04-25, Phase 0).** The Pulsar Book is scaffolded; chapters
> land progressively across Phase 1+ sprints. The full table of contents
> in `SUMMARY.md` is the canonical roadmap of intended chapters; chapters
> not yet written show as broken links until their sprint delivers.
>
> Until Sprint 4.5 expands this section, the **authoritative documents**
> for getting started are:
>
> - `README.md` — quick orientation.
> - `docs/plan.md` — the master plan with all 45+ strategic decisions.
> - `docs/architecture/overview.md` — seven-layer architecture map.
> - `docs/adr/INDEX.md` — architecture decision records.

## What is Pulsar?

Pulsar is a **state-of-art, regulated-domain Rust web framework**. It is
the second-generation rewrite of the PHP 8.5 Pulsar Framework
(`1.0.0-rc.11` baseline), redesigned around three orthogonal axes:

1. **Compile-time correctness over runtime checks.** Pulsar pushes as
   much error detection as possible into the type system. The borrow
   checker, sealed traits, capability tokens, and exhaustive matches do
   the work that runtime guards do in legacy frameworks. A program that
   compiles is closer to "correct by construction" than in any prior
   Pulsar release.

2. **Formal verification at the kernel.** The cryptographic primitives,
   the saga compensation engine, the right-to-be-forgotten two-phase
   commit, and the websocket lifecycle are each accompanied by a TLA+
   specification that is model-checked in CI on every commit touching
   the relevant crate. Function-level Creusot contracts cover the
   highest-stakes invariants.

3. **Compliance as code.** The 22 mandatory regulatory frameworks +
   MiCA opt-in (GDPR, HIPAA, PCI-DSS 4.0, PSD2, DORA, SOC 2, ISO 27001,
   ISO 27701, ISO 27018, NIS2, eIDAS 2.0, COPPA, FERPA, CCPA, LGPD,
   APPI, PIPL, POPIA, NDB, Brazilian LGPD, Mexican Federal Privacy Law,
   Indian DPDP Act, MiCA) are not a checklist — they are encoded as
   compile-checked policy expressions, and runtime enforcement is the
   default.

## Why Rust?

The PHP edition (`pulsar-framework` v1.0.0-rc.11) reached the limits of
what dynamic interpretation could deliver for the regulated-domain
workload mix:

| Dimension | PHP 8.5 baseline | Rust 1.95.0 target |
|-----------|------------------|--------------------|
| P99 HTTP latency | 14 ms | < 2 ms |
| Concurrent connections | 12 k | > 100 k |
| Memory per connection | 280 KiB | < 32 KiB |
| Cold start | ≥ 800 ms | < 50 ms |
| Binary size | n/a | < 25 MiB stripped |
| Verified components | none | 4 TLA+ specs + Creusot at kernel |

Beyond the numbers, Rust's ownership model gave us tools that simply
do not exist in PHP — capability passing without leakage, statically
proven absence of data races, zero-cost abstractions, and an ecosystem
of audited cryptographic primitives (`ring`, `rustls`, `subtle`,
`zeroize`, `secrecy`).

## Who is this for?

Pulsar is built for engineers shipping software that **must not fail**:
banking ledgers, healthcare records, government identity systems, legal
e-discovery pipelines. The defaults trade ergonomics for guarantees —
explicit context propagation, no global mutable state, no reflection in
hot paths, no `unwrap` outside tests. If your workload tolerates an
unannotated panic, Pulsar is overkill. If it doesn't, Pulsar removes
entire classes of bug from your scope.

## How to read this book

Linear reading is fine but not required. Suggested entry points:

- **First-time reader.** Start with [Installation](./getting-started/installation.md),
  follow the [Five-minute tour](./getting-started/five-minute-tour.md),
  then dip into [Architecture overview](./concepts/architecture.md).
- **Migrating from PHP Pulsar.** Read [Migration overview](./migration/overview.md)
  first; the [mapping table](./migration/mapping.md) is the day-to-day
  reference once migration starts.
- **Operator / SRE.** Skip to [Part V — Operations](./ops/topologies.md).
- **Compliance officer.** [Part VI — Compliance](./compliance/philosophy.md)
  cross-references every framework against the implementing crate.
- **Contributor.** [Part VIII — Contributing](./contributing/setup.md)
  walks through dev environment, coding standards, and quality gates.

## Where to ask questions

- **GitHub Discussions** for design questions and proposals.
- **Security-only matters** through GitHub Security Advisories per
  [`SECURITY.md`](./security-policy.md). Never file public issues for
  vulnerabilities.
- **Real-time chat** in the public Matrix room (link in `README.md`
  once the room is provisioned in Sprint 0.10).

## Versioning

Pulsar follows strict semver from `0.1.0` onward. The book is versioned
alongside the framework — every published mdBook deployment carries the
version it documents in the navbar. Older versions stay accessible at
`/pulsar-framework/v<major>.<minor>/`.
