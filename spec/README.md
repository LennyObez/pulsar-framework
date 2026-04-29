# Pulsar Framework — TLA+ specifications

This directory hosts the TLA+ specifications that constitute the formal-verification protocol surface of the Pulsar Framework Rust edition (per plan Section XII success metrics — **15 specifications at GA** per Decision 2.59 v2.3 lock-in).

## Files (each lands at its implementing sprint)

| File | Status | Verifies | Sprint |
|------|--------|----------|--------|
| `crypto.tla` | **landed (Phase 1.1.D.1)** | Key lifecycle states (Zeroised → Fresh → InUse → Rotating → Zeroised) — successor uniqueness, no nested rotations, Rotating eventually reaches Zeroised | Sprint 1.1 (`pulsar-kernel::crypto`) |
| `audit.tla` | pending | HMAC chain append + verify lifecycle — any single-byte tampering fails verification | Sprint 1.2 (`pulsar-kernel::audit`) |
| `session.tla` | pending | Session state-machine transitions — no path from `Anonymous` to `Elevated` bypasses `Authenticating` | Sprint 1.3 (`pulsar-kernel::session`) |
| `router.tla` | pending | Route resolution determinism on the radix-trie router | Sprint 1.4 (`pulsar-kernel::router`) |
| `middleware.tla` | pending | Middleware pipeline ordering + reentrancy — declared order equals execution order | Sprint 1.5 (`pulsar-kernel::middleware`) |
| `oauth2.tla` | pending | OAuth 2.1 authorisation code flow with PKCE — token issued only against verified PKCE; replay defence holds | Sprint 2.5 (`pulsar-auth`) |
| `saml.tla` | pending | SAML SSO with assertion replay defence | Sprint 2.5 (`pulsar-auth`) |
| `websocket.tla` | pending | WebSocket inbound dispatch lifecycle | Sprint 3B.2 (`pulsar-realtime::websocket`) |
| `orchestration.tla` | pending | Workflow state-reachability + saga compensation ordering | Sprint 3E.1 (`pulsar-orchestration`) |

Six additional v2.3 specifications (RtbF two-phase commit, capability token lifecycle, multi-region saga compensation, event-bus delivery semantics, ratelimit token-bucket invariants, OAuth refresh-token rotation) land at their respective sprints to reach the 15-spec GA target.

## Tooling

* **TLA+ Tools** v1.8.0 — `tla2tools.jar` containing TLC (model checker), SANY (parser), PlusCal (translator). Auto-downloaded by `spec/tools/install-tla.sh` on first run.
* **TLC** — bounded model checker; runs in CI via `.github/workflows/tla.yml` for every kernel sprint exit and for Sprints 2.5, 3B.2, 3E.1.
* **TLAPS** — TLA+ Proof System; optional, for invariants TLC cannot bound-check exhaustively.

Each `.tla` file ships with its `.cfg` model-checker configuration in this directory.

### Local runs

Prerequisite: any JDK ≥ 17 (Temurin / OpenJDK / Zulu / etc.) on `PATH`. Then:

```bash
spec/tools/run-tlc.sh crypto      # model-check spec/crypto.tla
```

The script downloads `tla2tools-1.8.0.jar` to `spec/tools/cache/` on first invocation (~2.5 MB), then invokes:

```bash
java -cp spec/tools/cache/tla2tools-1.8.0.jar tlc2.TLC \
    -config crypto.cfg -workers auto crypto.tla
```

The cache directory is gitignored — both local dev + CI re-fetch on first use, then reuse the cached JAR.

### CI

The `.github/workflows/tla.yml` workflow installs Temurin JDK 21 + caches the JAR + runs `spec/tools/run-tlc.sh <spec>` for every spec in its matrix. New specs are added to the matrix as their sprints land them.

## Convention

Per plan Section 17.27 formal verification scope:

* **Kernel crates** (`pulsar-kernel`): TLA+ specification + Creusot function contracts where tractable.
* **Security controls** (`pulsar-guard`): Creusot contracts on verification functions.
* **`pulsar-dataprotection` RtbF two-phase commit**: Creusot contracts plus property tests on commit + abort invariants.
* **All other crates**: property-based testing (`proptest`) as the formal-discipline floor.

Specifications follow Lamport's TLA+ Hyperbook conventions (`MODULE` declarations, `EXTENDS Naturals, Sequences, FiniteSets`, named `INVARIANT` and `PROPERTY` blocks for safety and liveness).

## References

* Lamport, L. "Specifying Systems: The TLA+ Language and Tools for Hardware and Software Engineers." Addison-Wesley, 2002.
* Klein, G., Elphinstone, K., Heiser, G., et al. "seL4: formal verification of an operating-system kernel." CACM, 2010.
* Plan Section II Decision 2.20 (formal verification: Creusot + TLA+).
* Plan Section XII success metrics (9 TLA+ specifications at GA).
* ADR-0003 (formally verified microkernel for crypto, audit, session, router, middleware, DI).
