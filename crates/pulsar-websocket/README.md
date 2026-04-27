# pulsar-websocket

WebSocket inbound dispatch + lifecycle + per-message backpressure, formally specified in `spec/websocket.tla` per Decision 2.59.

## What

Implements the RFC 6455 WebSocket protocol on top of `tokio-tungstenite`, layered with Pulsar-specific dispatch + lifecycle hooks: per-connection state machine (Connecting → Open → Closing → Closed), per-message backpressure (bounded channel between read loop and dispatcher), graceful close (RFC 6455 § 1.4 close codes preserved end-to-end), heartbeat ping/pong on a configurable interval, and authentication handshake binding the WebSocket session to an `pulsar-auth` session token.

## Why

WebSocket is the canonical primitive for live admin SPAs (`services/admin/`), live reactive frameworks (`pulsar-live`), and real-time pulsar-broadcasting fan-out. Reactive correctness across reconnects + disconnects + partial frames is famously hard to test exhaustively — the TLA+ specification at `spec/websocket.tla` (added in v2.2 per Decision 2.20 formal-verification scope) proves the lifecycle state-machine is sound under concurrent reads + writes + closes.

## How

`pulsar-websocket` is a v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51 (granular CVE blast-radius across the four realtime sub-protocols). It depends on `pulsar-http` for HTTP/1.1 + HTTP/2 upgrade handshakes, `pulsar-kernel` for capability gating on the post-handshake session, and `tokio-tungstenite` for the wire framing. The TLA+ spec runs in CI on every commit touching this crate.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3B.2 (historic `pulsar-realtime` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.38.1 + Section V.3B.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
