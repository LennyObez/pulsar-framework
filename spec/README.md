# Pulsar Framework — TLA+ specifications

This directory hosts the **nine TLA+ specifications** that constitute the formal-verification protocol surface of the Pulsar Framework Rust edition (per plan Section XII success metrics + ADR-0003).

## Files (each lands at its implementing sprint)

| File | Specification | Verifies | Sprint |
|------|---------------|----------|--------|
| `crypto.tla` | Key lifecycle states (Fresh, InUse, Rotating, Zeroised) | Key-state transitions are sound; no key reuse across rotation boundaries | Sprint 1.1 (`pulsar-kernel::crypto`) |
| `router.tla` | Route resolution determinism on the radix-trie router | Each path resolves to at most one route | Sprint 1.4 (`pulsar-kernel::router`) |
| `session.tla` | Session state-machine transitions | No path from `Anonymous` to `Elevated` bypasses `Authenticating` | Sprint 1.3 (`pulsar-kernel::session`) |
| `audit.tla` | HMAC chain append and verify lifecycle | Any single-byte tampering fails verification | Sprint 1.2 (`pulsar-kernel::audit`) |
| `middleware.tla` | Middleware pipeline ordering and reentrancy | No middleware runs twice per request; declared order equals execution order | Sprint 1.5 (`pulsar-kernel::middleware`) |
| `oauth2.tla` | OAuth 2.1 authorisation code flow with PKCE | Token issued only against verified PKCE; replay defence holds | Sprint 2.5 (`pulsar-auth`) |
| `saml.tla` | SAML SSO with assertion replay defence | Replayed assertions fail verification within the configured window | Sprint 2.5 (`pulsar-auth`) |
| `websocket.tla` | WebSocket inbound dispatch lifecycle | Frame ordering total within a connection; no handler runs after close | Sprint 3B.2 (`pulsar-realtime::websocket`) |
| `orchestration.tla` | Workflow state-reachability + saga compensation ordering | All declared workflow states are reachable; compensations execute in reverse order on abort | Sprint 3E.1 (`pulsar-orchestration`) |

## Tooling

* **TLA+ Toolbox** 1.8.x — developer-local install for spec authoring + visual debugging.
* **TLC** 1.8.x — model checker; runs in CI for every kernel sprint exit and for Sprints 2.5, 3B.2, 3E.1.
* **TLAPS** — TLA+ Proof System, optional for invariants TLC cannot bound-check exhaustively.

Each `.tla` file ships with its `.cfg` model-checker configuration in the same directory. CI invokes:

```bash
tlc -config <name>.cfg <name>.tla
```

per the kernel + protocol sprint quality gates (per `docs/plan.md` Section VI).

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
