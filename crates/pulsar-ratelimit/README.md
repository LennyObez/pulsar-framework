# pulsar-ratelimit

Token-bucket + leaky-bucket rate limiting with per-client + per-route + per-tenant quotas. Formally specified in `spec/ratelimit.tla` per Decision 2.59.

## What

Implements both algorithms (token bucket for burst tolerance, leaky bucket for steady-state shaping) with backends in-memory (single-instance) and Redis (multi-instance). Quota dimensions: per IP / per session / per API-key / per tenant / per route — composable via a builder. Rejected requests receive RFC 7234 `Retry-After` + RFC 6585 `429 Too Many Requests` per HTTP standards.

## Why

Without rate-limiting every public surface is one DoS away from an outage. Rate-limit primitives are also the seam where regulators check fairness invariants — financial-services regulators (NYDFS, MAS) require demonstrated per-customer fairness on shared API tiers. Pulsar formalises these invariants in TLA+ (`spec/ratelimit.tla`) so token-bucket monotonicity + per-client conservation are machine-checked.

## How

`pulsar-ratelimit` is a v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51. The Redis backend uses a Lua script for atomic refill+consume to avoid the race window of get-then-set. A new `spec/ratelimit.tla` (added in v2.3 per Decision 2.59) proves: refill is monotonic, no negative tokens, fairness across concurrent consumers.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 1.5. See [`docs/plan.md`](../../docs/plan.md) Section IV.11.4 + Section V.1.5.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
