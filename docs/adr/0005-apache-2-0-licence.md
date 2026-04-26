# ADR-0005: Apache License, Version 2.0 with EUIPO trademark protection

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.21 (Licence: Apache-2.0 with Pulsar Framework trademark registered at EUIPO), Decision 2.50 (Trademark + patent posture)
* **Sprint:** Sprint 0.5 (initial ADR batch)
* **Supersedes:** none

## Context

Pulsar Framework is open source and targets adoption by regulated downstream organisations (banking, healthcare, legal, government). The licence choice constrains:

1. **Procurement acceptance.** Regulated buyers' legal teams pre-approve a known set of licences. A licence outside that set adds weeks-to-months of legal review per adopter.
2. **Patent grant.** Some permissive licences (MIT, BSD-2/3, ISC) lack an explicit patent grant. Without one, a contributor with a relevant patent could later assert it against users — a procurement-blocking risk.
3. **Copyleft propagation.** GPL-family licences (GPL, AGPL, LGPL) propagate licence terms to derivative works. Regulated downstream applications often combine framework code with proprietary configuration and integration code; copyleft propagation creates uncertainty about which derivative scope is affected.
4. **Trademark separation.** Permissive licences allow forks and modifications. Without trademark protection, forks and unrelated derivatives can use the framework name — diluting brand recognition and creating confusion in regulator review.

Pulsar must satisfy all four constraints.

## Decision

Pulsar Framework is licensed under the **Apache License, Version 2.0** (SPDX identifier `Apache-2.0`). The full licence text is committed at `LICENSE` in the repository root, fetched verbatim from `https://www.apache.org/licenses/LICENSE-2.0.txt`.

The name **"Pulsar Framework"** is registered as a trademark with the **European Union Intellectual Property Office (EUIPO)** under the relevant trademark classes for software and software-as-a-service (Class 9, Class 42). Registration timeline per Decision 2.50: filing during Phase 0 (concurrent with the namespace-reservation alpha publish at `v0.0.1-alpha.0`); full registration target before `v0.5.0` core release. International extensions through the Madrid Protocol (WIPO) at `v1.0.0` GA targeting at minimum United Kingdom, United States, Switzerland, Canada, Australia, Singapore, and Japan.

The trademark policy is published in `docs/trademark-policy.md` (Sprint 0.7 deliverable) and governs permitted uses of the name and associated marks. The Apache-2.0 licence grants no trademark rights; trademark usage is governed exclusively by the trademark policy. Forks may use the name to identify they are forks (e.g. "fork of Pulsar Framework"); downstream applications may not use the mark in their own product name without a community licence; commercial entities require explicit permission for production use of the mark in marketing materials.

A `NOTICE` file at the repository root carries the Apache-2.0 boilerplate copyright, the EUIPO trademark disclaimer, and the third-party attribution policy.

## Consequences

### Positive

* Apache-2.0 is the dominant permissive licence in regulated procurement; most legal teams pre-approve it without per-project review.
* Explicit patent grant (Apache-2.0 Section 3) protects users against patent assertion by contributors. The defensive termination clause activates if a licensee initiates patent litigation alleging that the work or a contribution infringes their patents.
* No copyleft propagation; downstream applications combine Pulsar with proprietary code without licence concern.
* EUIPO trademark registration protects the brand across the EU single market; subsequent international extensions cover the major regulated-domain jurisdictions.
* Trademark policy + Apache-2.0 separation lets the project remain permissive on code while restrictive on brand — a known successful pattern (PostgreSQL, Rust language itself).
* OpenSSF Best Practices Badge, OSI listing, and FSF Free/Libre listing all accept Apache-2.0 without exception.

### Negative

* EUIPO registration carries an administrative fee and a calendar lead time of ~6-12 months from filing to full registration. The Phase 0 filing is the start of that clock; international extensions add additional cost per jurisdiction.
* Apache-2.0 NOTICE-file requirement adds a small contributor friction (every contribution must carry the same notice; mitigated by repository-level NOTICE).
* Apache-2.0 patent termination clause (Section 3) means a contributor's patent assertion against any user terminates that contributor's patent licence to the work. This can produce ambiguity if a contributor later asserts an unrelated patent.
* Trademark enforcement requires active monitoring — squatting attempts on the EU and international markets must be opposed.

### Neutral

* `cargo deny check licenses` allows Apache-2.0 by default in `deny.toml`. No additional configuration needed.
* The decision aligns with the Rust language and most of the Rust ecosystem — `cargo`, `tokio`, `hyper`, `rustls`, `serde`, `sqlx`, etc. — minimising licence-mixing complexity in the dep graph.
* CLA (Contributor License Agreement) is not required; Apache-2.0's inbound-equals-outbound model handles contributor IP assignment implicitly.

## Alternatives considered

* **MIT.**
  Rejected: lacks an explicit patent grant. Some procurement teams flag this as a gap.
* **BSD-2-Clause / BSD-3-Clause / ISC.**
  Rejected: same patent-grant gap as MIT. The "BSD without warranty" reputation is sometimes preferred but does not address the patent-grant requirement.
* **MPL-2.0 (Mozilla Public License 2.0).**
  Rejected: file-level copyleft introduces friction in regulated forks. Some adopters interpret MPL-2.0 propagation strictly enough to require disclosure of customisation files.
* **GPL-3.0 / AGPL-3.0.**
  Rejected: strong copyleft incompatible with closed-source downstream applications. AGPL would require service-provider downstream applications to publish their full source on user request — disqualifying for most regulated commercial deployments.
* **LGPL-3.0.**
  Rejected: file-level copyleft on LGPL-only files; ambiguity around dynamic vs static linking on Rust crates.
* **Source-available licence (e.g. BSL, ELv2, SSPL).**
  Rejected: these are not OSS-approved; OSI listing required for procurement comfort. Source-available licences are sometimes adopted by venture-backed projects to forbid commercial competition; Pulsar's funding model (Decision 2.49 GitHub Sponsors + Open Collective) does not require this.
* **Dual licence Apache-2.0 + commercial.**
  Decision 2.49 reserves a *post-GA optional commercial path* for organisations whose procurement requires a counterparty contract. Apache-2.0 remains the only OSS licence; the commercial path is a parallel grant covering trademark, indemnification, and prioritised support — not a different code licence.
* **No trademark registration.**
  Rejected per Decision 2.50: brand vulnerability window during Phase 0-4 is unacceptable.
* **Trademark via USPTO instead of EUIPO.**
  Rejected as primary registration: the maintainer is EU-based, the procurement audience is EU-regulated, and the EUIPO procedure is cheaper and faster for the home-jurisdiction filing. USPTO + Madrid Protocol extensions land at GA per Decision 2.50.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.21 + 2.50, Section IX release strategy (licence in workspace package metadata).
* Risk register entries: (none directly — trademark-related risk is implicit in brand vulnerability discussed in 2.50)
* Related ADRs: ADR-0001 (full rewrite in Rust), ADR-0006 (crates.io `pulsar-*` namespace), ADR-0007 (branch model — for licence enforcement in CI).
* External:
  * Apache Software Foundation. "Apache License, Version 2.0." apache.org/licenses/LICENSE-2.0.
  * SPDX. "Apache-2.0 licence identifier." spdx.org/licenses/Apache-2.0.html.
  * EUIPO. "Trade mark applications." euipo.europa.eu.
  * WIPO. "Madrid Protocol concerning the international registration of marks." wipo.int/madrid.
  * Open Source Initiative. "Apache License 2.0." opensource.org/license/apache-2-0.

## Compliance mapping

The licence + trademark posture is upstream of every procurement-side compliance question. Apache-2.0 is the OSI-approved licence that maximises downstream procurement compatibility; the EUIPO trademark + defensive patent grant + future commercial dual-licence path collectively answer the procurement, IP, and counterparty-contract questions regulated buyers raise.

* **ISO/IEC 5230:2020 (OpenChain Conformance)** — Apache-2.0 is on the OpenChain-recommended permissive licence list. Per-crate `license = "Apache-2.0"` declaration in every `Cargo.toml` + `LICENSE` file at repo root + `NOTICE` file with attribution + SBOM emitted by `publish.yml` collectively satisfy OpenChain § 3.1 (Identification of License Obligations) + § 3.2 (Compliance Artifact Creation).
* **ISO/IEC 18974:2023 (OpenChain Security Assurance)** — Apache-2.0 + the SSDLC controls in ADR-0007 + the SBOM emission in ADR-0006 collectively support 18974's process-based security-assurance claims downstream procurement evaluators check.
* **ISO/IEC 5962:2021 (SPDX)** — `Apache-2.0` SPDX identifier is the canonical machine-readable licence tag. CycloneDX SBOMs emitted by `publish.yml` carry SPDX identifiers per crate per 5962.
* **EU CRA Annex II § 1(d)** (information and instructions to user) — Apache-2.0 grants downstream users explicit rights to use, modify, redistribute. The trademark policy (`docs/trademark-policy.md`) explicitly limits "Pulsar" mark use so downstream rebrandings cannot misrepresent themselves as endorsed.
* **EU CRA Annex II § 2(g)** (security update period) — Apache-2.0 permits AS-IS distribution; Pulsar's security-update commitment is documented in `SECURITY.md` independently of the licence.
* **EU AI Act Art. 25** (obligations of distributors) — Apache-2.0 + EUIPO trademark + immutable signed releases per ADR-0006 + ADR-0007 collectively support distributor-obligation compliance for downstream applications using `pulsar-ai`.
* **GDPR Art. 28(3)** (binding contract between controller and processor) — Apache-2.0 itself is not a processor agreement; the post-GA commercial dual-licence path (Decision 2.49) provides the counterparty contract regulated controllers may require for processor relationships involving Pulsar-hosted services.
* **OpenSSF Best Practices Badge — Gold tier** — Section 16.12.8 target. Apache-2.0 + clear `LICENSE` + `CONTRIBUTING.md` + signed releases + reproducible builds + SBOM are gold-tier criteria the licence + branch + supply-chain ADRs collectively satisfy.
* **WIPO Madrid Protocol** — EUIPO base registration (Phase 0) + Madrid Protocol international extensions at GA covering UK, US, CH, CA, AU, SG, JP per Decision 2.50.
