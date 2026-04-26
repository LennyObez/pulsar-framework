# Pulsar Framework — Examples

Reference applications demonstrating Pulsar Framework idioms across the four target verticals (banking, healthcare, legal, government) plus a few cross-vertical patterns. Each example is a standalone Rust binary crate in its own directory; examples are **not** workspace members so they can demonstrate downstream usage of `pulsar-framework` exactly as a real adopter would.

## Examples (per plan Section IV tree)

| Example | Vertical | Demonstrates |
|---------|----------|--------------|
| `hello-world/` | cross-vertical | Minimal pulsar-framework consumer; smoke-test parity with the v0.1.0 release artefact |
| `banking-ledger/` | banking | Event-sourced ledger aggregate + audit chain + DORA reporting |
| `healthcare-fhir/` | healthcare | HL7 FHIR R5 resource server + HIPAA BAA template + consent ledger |
| `legal-case-mgmt/` | legal | Case management workflow + eIDAS 2 EUDI Wallet integration |
| `gov-identity/` | government | RISC + CAEP continuous evaluation + WebAuthn + SAML |
| `cms-blog/` | cross-vertical | `pulsar-cms` consumer with public pages and admin SPA |
| `forum-community/` | cross-vertical | `pulsar-forum` with moderation, reactions, full-text search |
| `payments-subscription/` | cross-vertical | Stripe subscription billing with proration + dunning + tax computation |
| `live-dashboard/` | cross-vertical | `pulsar-live` reactive server-driven dashboard |
| `ai-chatbot-rag/` | cross-vertical | RAG-grounded chatbot with `pulsar-ai`, `pulsar-vector-search`, `pulsar-ai-governance` audit + redaction |

## Status

All ten examples are scaffolded as placeholder binary crates that depend on the in-development `pulsar-framework`. Each example's `src/main.rs` prints a banner stating which sprint is expected to land its full implementation.

Per plan Section V each example matures alongside the framework crate it demonstrates:

* `hello-world/` — first end-to-end Phase 0 → Phase 1 transition (Sprint 1.6 kernel close)
* `banking-ledger/` — Phase 2A core runtime (Sprint 2.3 ORM `#[EventSourced]` derive)
* `healthcare-fhir/` — Phase 2C data protection + HSM (Sprint 2C.3)
* `legal-case-mgmt/` — Phase 3E orchestration (Sprint 3E.1 `pulsar-orchestration`)
* `gov-identity/` — Phase 2A authentication (Sprint 2.5 `pulsar-auth`)
* `cms-blog/` — Phase 3A application extensions (Sprint 3.2 `pulsar-cms`)
* `forum-community/` — Phase 3A (Sprint 3.3 `pulsar-forum`)
* `payments-subscription/` — Phase 3A (Sprint 3.4 `pulsar-payments`)
* `live-dashboard/` — Phase 3D reactive (Sprint 3D.1 `pulsar-live`)
* `ai-chatbot-rag/` — Phase 3C AI surface (Sprint 3C.2 + 3C.3 + 3C.4)

## Running an example

Once the target framework version ships:

```bash
cd examples/<name>
cargo run
```

Examples are intentionally **outside** the workspace so they exercise the public surface as a downstream consumer would. Each example's `Cargo.toml` declares `pulsar-framework` and the specific extension crates it needs as path dependencies (resolving to the workspace) for development; release-mode examples pin to the published crates.io versions.
