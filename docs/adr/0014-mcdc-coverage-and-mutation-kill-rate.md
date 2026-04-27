# ADR-0014: 100% line + branch + MC/DC coverage + 99% mutation kill rate on critical-tier crates

* **Status:** Accepted
* **Date:** 2026-04-27
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.56 (Coverage discipline — 100% line + branch + MC/DC + 99% mutation on kernel/security/compliance/dataprotection), Decision 2.28 (Testing baseline), Decision 2.20 (Formal verification scope)
* **Sprint:** Sprint 0.9-bis (v2.3 reconciliation closure); enforcement begins Sprint 1.1 (kernel crypto, the first critical-tier crate sprint)
* **Supersedes:** Section VI v2.2 quality-gate thresholds for mutation testing (was ≥ 95%, now ≥ 99% on critical tier)

## Context

The v2.2 reconciliation set the test-discipline gates for the four critical-tier crate groups (kernel + security controls + compliance + data protection) as:

| Gate | v2.2 threshold (critical tier) |
|------|----------|
| Line coverage | 100% |
| Branch coverage | 100% |
| MC/DC coverage | 100% |
| Mutation kill rate | ≥ 95% |

The v2.3 reconciliation interactive validation pass identified that the 95% mutation threshold leaves **5% of mutations undetected** — equivalent to "for every 20 hand-written tests, 1 mutation slips past the test suite without being killed". For the regulated-domain critical tier, that 5% gap is the gap that test-discipline-conscious downstream procurement evaluates.

**Mutation testing** is the discipline of automatically introducing small-grain mutations to the source under test (changing `<` to `<=`, swapping `&&` to `||`, deleting return statements, replacing constants) and observing which mutations the test suite catches. A "killed" mutation is one that causes at least one test to fail; a "surviving" mutation is one that the test suite passes despite the introduced change. Surviving mutations indicate either:

* Dead code that no test exercises (covered but not tested for behaviour).
* Weak assertions that do not differentiate between mutated and original behaviour.
* Defect classes that the test suite is structurally unable to detect.

The mutation kill rate is the fraction of mutations the test suite kills. State-of-art:

* **Academic mutation testing literature** (Andrews et al. 2005; Just et al. 2014) treats ≥ 95% as the operationally-acceptable threshold for general-purpose code.
* **PIT (Pitest) for Java** at production-grade aims for 80-90% (industry-typical).
* **SQLite** (the Pulsar reference for test discipline per Decision 2.3) achieves 100% MC/DC + comprehensive mutation testing.
* **`cargo-mutants` mature in 2026** — actively maintained, integrates with `cargo` workspace + `cargo nextest`, mature enough for CI gating.

For the regulated-domain critical tier, **99% mutation kill rate** matches the academic state-of-art for mutation-discipline projects + represents a strictly stronger guarantee than the 95% v2.2 threshold. The marginal cost (additional tests + assertion-strength refinement) is bounded — `cargo-mutants` reports surviving mutations with file + line + diff, so the workflow is "run cargo-mutants → triage surviving mutations → write a test that kills each one → re-run".

**MC/DC** (Modified Condition / Decision Coverage) is the coverage criterion from **DO-178C Level A** — the highest aviation safety integrity level, used for primary flight controls in commercial aviation (Airbus A320 fly-by-wire, Boeing 787, Embraer E-Jet). The MC/DC criterion requires that every condition in every boolean decision **independently affects** the outcome of the decision in the test suite. This catches the "we tested both branches but never in the combination that exposes the defect" class of bug.

`cargo llvm-cov --mcdc` (LLVM-backed) provides MC/DC measurement in 2026 via the `--mcdc` flag introduced in LLVM 19.

## Decision

Pulsar Framework's critical-tier crates (`pulsar-kernel`, the six security-controls crates from the Decision 2.51 dé-fusion, `pulsar-compliance`, `pulsar-dataprotection`) must achieve at sprint exit:

| Gate | Critical tier | Other crates |
|------|---------------|--------------|
| Line coverage | 100% | ≥ 95% |
| Branch coverage | 100% | ≥ 95% |
| MC/DC coverage | 100% | informative only |
| Mutation kill rate | ≥ 99% | ≥ 90% (informative — non-blocking) |

CI gates fail the build on any of:

* Line coverage < 100% (critical) or < 95% (rest).
* Branch coverage < 100% (critical) or < 95% (rest).
* MC/DC < 100% (critical only — informative on the rest).
* Mutation kill rate < 99% (critical only).

Toolchain:

* `cargo llvm-cov --workspace --branch --mcdc` for line + branch + MC/DC.
* `cargo mutants --workspace --minimum-test-timeout 60` for mutation testing.

Critical-tier crates enumerated:

* `pulsar-kernel` — formally verified microkernel.
* `pulsar-csrf`, `pulsar-sri`, `pulsar-incident`, `pulsar-ratelimit`, `pulsar-resilience`, `pulsar-ssrf-guard` — the six security-controls crates from the v2.3 dé-fusion of `pulsar-guard`.
* `pulsar-compliance` — compliance framework mappings + control points.
* `pulsar-dataprotection` — RtbF two-phase commit + redaction + consent ledger.
* `pulsar-crypto-hacl-bindings` — HACL\* FFI binding crate (the safe wrappers + Creusot contracts live in `pulsar-kernel`, but the FFI binding crate is critical-tier by association).

The remaining 65 crates target ≥ 95% line + branch and best-effort mutation (informative only, non-blocking).

## Consequences

### Positive

* **Test discipline at SQLite + DO-178C Level A tier** for the four critical-tier crate groups. SQLite achieves 100% MC/DC; DO-178C Level A mandates MC/DC for primary flight controls. Pulsar matches both on the regulator-evaluated surface.
* **99% mutation kill rate** closes the assertion-strength gap that 95% leaves open. Test suites at this tier are structurally unable to miss defect classes within the mutation operator's coverage.
* Auditable evidence for downstream procurement — `cargo-mutants` + `cargo llvm-cov` reports are machine-readable and can be archived alongside ADRs as part of the release artefact.
* CI gates make regression mechanically impossible — a sprint exit that drops below threshold is rejected by the pipeline.
* Aligns with Section XII success metrics + ADR-0003 (microkernel formal verification — coverage discipline + formal verification jointly cover the assurance space).

### Negative

* Test development cost grows. Achieving 99% mutation kill rate on cryptographic + capability-token + audit-chain code requires careful assertion engineering. Mitigation: bounded to four crate groups, not the entire workspace.
* CI duration grows. `cargo mutants` on the kernel surface ≈ 30-60 minutes per sprint exit; runs only on critical-tier crate sprints (not every PR). Off-critical-tier sprints run mutation as informative-only (non-blocking).
* MC/DC measurement requires LLVM 19+ — pinned via `rust-toolchain.toml` per Decision 2.7. Older toolchains cannot measure MC/DC.
* `cargo-mutants` is a Rust-ecosystem-native tool (no equivalent for the Ada/SPARK module per ADR-0010) — the SPARK invariants module relies on GNATprove proof reports as its assurance signal, not mutation testing.

### Neutral

* The remaining 65 crates' ≥ 95% line + branch threshold is unchanged from v2.2.
* Mutation testing on non-critical-tier crates remains informative — surviving mutations surface in CI logs as warnings but do not block sprint exit.
* Property-based testing (`proptest`) and fuzz testing (`cargo-fuzz`) remain orthogonal disciplines per Section XII metrics; they complement coverage + mutation rather than substitute.

## Alternatives considered

* **Keep 95% mutation threshold (v2.2 baseline)**.
  Rejected. The 5% gap leaves the assertion-strength axis under-attested for the regulated-domain tier where defect cost is denominated in regulatory exposure.
* **100% mutation kill rate**.
  Rejected. 100% is operationally unreachable on cryptographic code where some mutations are equivalent (e.g. swapping the order of HMAC inputs in a position-symmetric construction). The 99% threshold corresponds to ≥ 99% non-equivalent kill rate per the academic literature.
* **MC/DC across the entire workspace**.
  Rejected. MC/DC measurement is expensive (≈ 5-10× line-coverage measurement cost) and the marginal benefit on non-critical-tier crates does not justify the cost. Critical-tier MC/DC + non-critical-tier line + branch is the optimum.
* **No mutation enforcement, only coverage**.
  Rejected. Coverage measures whether code was executed; mutation measures whether the assertions are strong enough to catch defects. The two are independent — high coverage with weak assertions passes coverage gates while letting defects through.
* **Adopt PIT-style mutation operators only (no `cargo-mutants` Rust-specific operators)**.
  Rejected. `cargo-mutants` 2026 ships a balanced operator set tuned for Rust idioms (e.g. `Result::Ok` ↔ `Result::Err` swaps, `Option::Some` deletions). PIT-style alone would miss Rust-idiom defects.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.56 + 2.28 + 2.3, Section VI quality-gate thresholds, Section XII success metrics (mutation kill rate + MC/DC rows).
* Risk register entries: R-013 (parity scope slippage — mutation gating mitigates).
* Related ADRs: ADR-0003 (microkernel formal verification — coverage + formal verification jointly cover the assurance space), ADR-0011 (dé-fusion — defines the six security-controls crates that fall in critical tier).
* External:
  * `cargo-mutants` upstream: https://github.com/sourcefrog/cargo-mutants
  * `cargo llvm-cov` upstream: https://github.com/taiki-e/cargo-llvm-cov
  * SQLite testing methodology: https://sqlite.org/testing.html
  * DO-178C standard: https://www.rtca.org/content/standards-guidance-materials
  * Andrews, J.H. et al. "Is Mutation an Appropriate Tool for Testing Experiments?" ICSE 2005.
  * Just, R. et al. "Are Mutants a Valid Substitute for Real Faults in Software Testing?" FSE 2014.
  * LLVM 19 MC/DC support: https://llvm.org/docs/CommandGuide/llvm-cov.html#mcdc-coverage

## Compliance mapping

* **DO-178C Level A** — MC/DC is the canonical coverage criterion at the highest avionics safety integrity level. Pulsar does not pursue DO-178C certification but the methodology lineage strengthens the assurance claim for downstream regulated deployments.
* **ISO/IEC 25010:2011** (system / software quality model) — Reliability sub-characteristic (Maturity, Fault Tolerance) is directly improved by 99% mutation kill rate + 100% MC/DC.
* **ISO/IEC 27034-3:2018** (application security management process) — § 7 verification clause is satisfied at the highest test-discipline tier.
* **NIST SP 800-218 SSDF — PW.7** (review and/or analyse human-readable code to identify vulnerabilities and verify compliance with security requirements) — high mutation kill rate is the strongest available evidence that test-based code review catches vulnerability-relevant defects.
* **NIST SP 800-53 Rev. 5 SA-11** (developer testing and evaluation) — SA-11(8) (dynamic code analysis) is satisfied by `cargo-mutants` + `cargo llvm-cov` discipline.
* **PCI-DSS 4.0 Req. 6.2.3.1** (custom and bespoke software is reviewed and approved before being released) — coverage + mutation reports are the machine-readable evidence of pre-release verification rigor.
* **EU CRA (Regulation (EU) 2024/2847) Annex I § 1(c)** (designed, developed and produced to ensure security with regard to the level of risks) — DO-178C Level A-equivalent test discipline on the regulator-evaluated surface is the strongest available "level of risks" evidence.
* **Common Criteria EAL 4+** (Decision 2.52 target — Common Criteria EAL 6+/7) — MC/DC + mutation kill rate are recognised evidence under ATE_DPT (testing — depth) at EAL 4+ and become mandatory at higher EALs.
