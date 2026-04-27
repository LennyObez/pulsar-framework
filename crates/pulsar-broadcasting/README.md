# pulsar-broadcasting

Broadcasting + presence tracking across realtime channels with Redis pub/sub fan-out.

## What

Cross-instance broadcasting layer that fans out events from a single publisher to multiple subscribers across multiple Pulsar instances via Redis pub/sub. Composes with `pulsar-websocket`, `pulsar-sse`, and `pulsar-webtransport` so an event published in one channel reaches every connected client regardless of which Pulsar instance terminates the connection. Adds presence tracking: each channel maintains a roster of currently connected client identifiers (with TTL-based eviction on disconnect).

## Why

A live admin dashboard, a real-time notification feed, or a multi-user collaborative editor all need cross-instance broadcasting — the publishing event might originate on instance A but the subscribed client is connected to instance B. Without a shared bus this requires sticky-session routing or socket migration; with the bus the routing is invisible. Presence tracking adds the secondary user-visible signal "who else is here right now" that collaborative interfaces rely on.

## How

`pulsar-broadcasting` is a v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51. Backend defaults to Redis pub/sub (mature, ubiquitous, enough latency budget for human-interactive UIs); pluggable per Decision 2.22 hexagonal pattern so NATS or Kafka can substitute. Presence tracking uses a sorted set keyed by channel + member with score = TTL.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3B.2 (historic `pulsar-realtime` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.38.4 + Section V.3B.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
