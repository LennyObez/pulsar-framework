# ADR-0010: Ada/SPARK 2014 module for capability + audit-chain invariants

* **Status:** Accepted
* **Date:** 2026-04-27
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.54 (Sub-module SPARK 2014 for capability token unforgeability + audit chain append-only invariants), Decision 2.20 (Formal verification: Creusot + TLA+ + HACL\* + SPARK 2014), Decision 2.46 (Polyglot acceptance: Go for K8s operator)
* **Sprint:** Sprint 0.9-bis (v2.3 reconciliation closure); implementation Sprint 1.2 (capability + audit kernel sprint)
* **Supersedes:** none

## Context

Pulsar Framework's kernel surface includes two invariants whose violation is **catastrophic** in regulatory + criminal terms:

1. **Capability token unforgeability** — if any execution path can produce a `Capability_Token` that the verifier accepts without that token having been minted by an authorised caller, the entire capability-based security model collapses. Privilege escalation across every authorised surface (admin SPA, gRPC API, MCP server, AI agents) becomes trivial.
2. **Audit chain append-only enforcement** — if any `(Prev_Root, Entry, New_Root)` triple admits more than one `New_Root` value satisfying the verifier, the audit chain ceases to be tamper-evident. Regulatory evidence chains (DORA Art. 9(2), HIPAA 45 CFR 164.312(b), PCI-DSS 4.0 Req. 10) become unreliable.

Both invariants are conceptually simple but require **cross-function quantification** in their formal statement: "for all minted-by paths" / "for all admissible roots". Creusot (the Rust function-contract prover used per Decision 2.20) is improving rapidly in 2026 but its support for `for all` quantifiers + abstract membership predicates + refinement of ghost types is still partial; achieving machine-checked proof of these specific invariants in Creusot would require multiple workarounds + manual lemma chains that GNATprove handles natively.

The remaining options for the invariant proofs:

* **Stay in Rust + Creusot, accept the partial proof tier** — leaves the highest-stakes invariants under-attested, contrary to the regulated-domain ambition.
* **Use Coq or Lean as an external proof environment** — proof-translation effort is substantial; the proof remains separated from the implementation.
* **Use SPARK 2014 + GNATprove for these specific functions** — native quantified-contract support, in-source proofs, mature tooling, industrial track record (Airbus A350, Boeing 787 partial, Eurofighter, defence ministries FR/UK/US).

## Decision

A new sub-project **`services/spark-invariants/`** written in **Ada/SPARK 2014** holds the formal proofs for the two invariants. The proven verifiers are exposed via FFI to `pulsar-kernel`. The polyglot is acceptable per Decision 2.46.

**Scope:** Two Ada packages totalling ~500-1000 lines of SPARK Mode (On) code at sprint exit:

* `Capability_Unforgeability` (`capability_unforgeability.ads` + `.adb`):
  * `Verify_Capability(Tok, Set) → Boolean` with postcondition tying the result to a ghost membership predicate `Is_Member`.
  * `Mint_Capability(Tok, Set, Caller)` with precondition `Is_Member(Caller, Set)` (only authorised callers can mint) and postcondition `Is_Member(Tok, Set)` (newly-minted token is in the set).
  * Cross-function invariant: `Is_Member(Tok, Set)` is True only if `Mint_Capability` produced `Tok`.

* `Audit_Chain_Append_Only` (`audit_chain_append_only.ads` + `.adb`):
  * `Verify_Append(Prev_Root, Entry, New_Root) → Boolean` with postcondition: if the result is True then `New_Root` is the unique value satisfying the verifier with the given inputs (`for all Alt_Root => (if Verify_Append(Prev_Root, Entry, Alt_Root) then Alt_Root = New_Root)`).
  * The unique-determinacy postcondition is the formal expression of "append-only" — no two distinct `New_Root` values satisfy the same `(Prev_Root, Entry)` extension under the RFC 6962 Merkle construction.

**What does NOT live in SPARK:**

* The Rust-side `pulsar-kernel` (capability table runtime, audit chain runtime, all I/O, all serialisation, all protocol logic) stays in Rust + Creusot + TLA+.
* All cryptographic primitives stay in HACL\* per ADR-0009 (the SPARK code calls SHA-256 + Ed25519 via the same HACL\* C ABI, not via duplicate SPARK implementations).
* All concurrency, async, networking, persistence stays in Rust.

**Toolchain:**

* **GNAT Community 2026** (GNAT Pro for production builds — both produce identical binaries with the same SPARK Mode discipline).
* **GNATprove 2026** with Z3, CVC5, Alt-Ergo SMT solvers (proof level 4).
* **Alire** (Ada package manager) for any third-party Ada deps (currently none — module is self-contained).
* GPR project file (`pulsar_invariants.gpr`) drives `gprbuild` (compile) + `gnatprove` (prove).

**FFI integration:** the SPARK module exports two C symbols via `pragma Export`: `pulsar_spark_verify_capability` + `pulsar_spark_verify_audit_append`. The Rust-side `pulsar-crypto-hacl-bindings` (or eventually a sibling `pulsar-spark-bindings` if kernel scope warrants) declares the FFI signatures + `pulsar-kernel::crypto::capability` + `pulsar-kernel::audit::merkle` modules consume them through safe wrappers + Creusot contracts on the call-site invariants.

## Consequences

### Positive

* The two highest-assurance kernel invariants gain native formal proof at a tier strictly stronger than Rust + Creusot for these specific properties.
* SPARK is industrially mature (Airbus, Boeing, Eurofighter ATC, Eurocontrol) — proven tooling, not academic-research-grade.
* Common Criteria EAL 6+/7 evaluators recognise SPARK proofs as evidence at the highest CC tiers.
* The proof + implementation live in the same source — verification is not a separate document that drifts.
* Ada `Storage_Element` array types map cleanly to Rust `[u8]` slices via FFI.

### Negative

* Polyglot expands from Rust + Go + JS to Rust + Go + JS + Ada/SPARK. Maintainer must learn SPARK 2014; contributor pool narrows for this sub-project (mitigated by sub-module being ~500-1000 LOC total).
* GNAT toolchain dependency in CI + contributor environment. Mitigated by Nix flake + devcontainer per Decision 2.57 reproducibility.
* GNATprove proof time dominates CI duration on this sub-project (level 4 + multiple solvers can take 5-30 minutes per package depending on contract complexity).
* SPARK ↔ Rust FFI requires careful boundary discipline (no Rust panics across FFI; SPARK never raises exceptions to FFI).

### Neutral

* The SPARK module is a private implementation detail — not a published crates.io artefact. Downstream consumers of `pulsar-kernel` see only the Rust safe API.
* Sprint 1.2 (capability + audit kernel sprint) is the implementation sprint; until then the SPARK bodies return placeholder "verification declined" while the contracts are declared.

## Alternatives considered

* **Stay Rust + Creusot for these invariants too**.
  Rejected: Creusot 2026 partial support for cross-function quantified contracts requires multiple workarounds + manual lemma chains that GNATprove handles natively. The cost of those workarounds is comparable to the cost of the polyglot — but with weaker proof tier outcome.
* **Use Coq or Lean for these proofs**.
  Rejected: requires translating Rust semantics into the proof tool. SPARK keeps the proof in-source. Coq/Lean proofs of OS-level invariants exist (seL4 used Isabelle/HOL) but require ~10× the effort of SPARK + GNATprove for comparable invariant scope.
* **Use Kani (bounded model checker)**.
  Rejected: bounded model checking proves properties up to a depth bound, not for all admissible inputs. Useful for fuzzing-equivalent symbolic exploration but not a replacement for SPARK's inductive proof.
* **Use Isabelle/HOL with `proof-of-equivalent-Rust` (the seL4-style methodology)**.
  Rejected: industrial proof effort scale. seL4 cost ~25 person-years for the kernel proof; the Pulsar SPARK module targets a fraction of one person-year per invariant.
* **Move the entire kernel to SPARK (per Decision 2.54 alternatives table)**.
  Rejected: arrête mortellement le projet — no Ada/SPARK web ecosystem (no equivalent to tokio, hyper, sqlx, ring) means full kernel-in-SPARK requires reimplementing the async runtime + HTTP stack + DB client in SPARK = 5-10 person-years extra. Not feasible for a multi-year solo project.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.54 + 2.20 + 2.46, Section IV Workspace Layout (services/spark-invariants/), Section XII success metrics (2 SPARK invariants row).
* Risk register entries: R-001 (Creusot prover coverage gap — SPARK closes for the cross-function quantified subset).
* Related ADRs: ADR-0003 (microkernel formal verification), ADR-0009 (HACL\* crypto — sibling decision in v2.3), ADR-0007 (branch model — covers FFI boundary discipline).
* External:
  * SPARK 2014 Reference Manual: https://docs.adacore.com/spark2014-docs/html/lrm/
  * GNATprove user guide: https://docs.adacore.com/spark2014-docs/html/ug/
  * AdaCore "SPARK Pro" page: https://www.adacore.com/about-spark
  * Klein, G., Elphinstone, K., Heiser, G., et al. "seL4: formal verification of an operating-system kernel." CACM, 2010 (the methodology this module borrows + scales down).
  * Chapman, R. "SPARK: A Tale of Two Standards." Ada User Journal, 2015.
  * RFC 6962 (Certificate Transparency Merkle tree): https://datatracker.ietf.org/doc/html/rfc6962

## Compliance mapping

* **Common Criteria ISO/IEC 15408 — EAL 6+ / EAL 7** (Section 16.12.9 target) — formal proof of high-stakes invariants supports the highest CC tiers. SPARK 2014 + GNATprove proof reports are recognised evidence under ATE_DPT.4 + ADV_FSP.6 + ADV_TDS.6.
* **DO-178C Level A** (avionics safety) — SPARK 2014 is one of the canonical languages for DO-178C Level A development; proven invariants meet the highest avionics safety integrity level. Pulsar does not pursue DO-178C certification but the methodology lineage strengthens the assurance claim.
* **NIST SP 800-160 Vol. 1 Rev. 1** (engineering trustworthy secure systems) — § 3.4.4 verification clause is satisfied at the highest tier (formal proof) for the two SPARK-proven invariants.
* **ISO/IEC 27001:2022 A.8.27** (secure system architecture and engineering principles) — formal-proof artefacts are the strongest available form of secure-architecture documentation.
* **HIPAA 45 CFR § 164.312(b)** (audit controls) — append-only audit chain enforcement formally proven supports HIPAA's audit-control requirement at a tier exceeding the regulatory baseline.
* **PCI-DSS 4.0 Req. 10.5** (audit log integrity) — Verify_Append uniqueness postcondition formally proves log integrity; PCI-DSS auditors recognise formal proofs as the strongest evidence form.
* **DORA Art. 9(2)(c)** (preventive measures — authentication of users and devices) — capability token unforgeability formally proven means that capability-based authentication cannot be bypassed by token forgery.
