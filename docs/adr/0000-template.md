# ADR-NNNN: Short title that names the decision

* **Status:** Proposed | Accepted | Deprecated | Superseded by ADR-XXXX
* **Date:** YYYY-MM-DD
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.X (cross-reference if applicable)
* **Sprint:** Sprint X.Y (sprint that issues or implements this ADR)
* **Supersedes:** ADR-NNNN (if this replaces a prior decision) — none by default

## Context

State the problem that the decision addresses. Describe the constraints (regulatory, technical, organisational) that bound the solution space. Reference the relevant plan section, risk register entry, or upstream issue. Keep this section descriptive, not prescriptive: the reader should understand WHY a decision is needed before reading what was decided.

## Decision

State the decision in declarative form. One sentence, then a paragraph of detail. Example:

> The Pulsar Framework Rust edition adopts the formally verified microkernel pattern, hosting the cryptographic primitives, audit chain, session state machine, router trie, middleware pipeline, and dependency-injection container under TLA+ specifications and Creusot function contracts.

Follow with mechanism: what artefacts implement the decision, what configuration enforces it, what tests verify it.

## Consequences

### Positive

* List the benefits this decision delivers (correctness, security, performance, maintainability, etc.)
* Each item is a measurable or auditable outcome — not just an aspiration.

### Negative

* List the costs this decision imposes (build complexity, contributor learning curve, dependency surface, etc.)
* Be honest. A decision without negatives is suspicious.

### Neutral

* List the side effects that are neither clearly good nor bad but worth recording (changes in idiom, third-party tool dependencies, future migration paths).

## Alternatives considered

For each alternative:

* **Name** — one-line description.
  Why rejected. Reference the constraint or risk that excludes it.

Alternatives must be named even when obviously inferior — the discipline of naming forces the rejection rationale to be explicit and audit-traceable.

## References

* Plan section(s): `docs/plan.md` Section X.Y
* Risk register entries: R-XXX (`docs/plan.md` Section X risk register)
* Related ADRs: ADR-NNNN, ADR-NNNN
* External: papers, RFCs, upstream issues, vendor docs

## Compliance mapping

If the decision affects a regulatory framework mapping (per `docs/compliance/matrix.md`), list the affected clauses here. Otherwise omit this section.

* GDPR Art. X
* ISO 27001:2022 control A.X.Y
* DORA Art. X
* (etc.)
