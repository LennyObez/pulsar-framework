# pulsar-webtransport

WebTransport bidirectional streams + datagrams over HTTP/3 (QUIC) per W3C WebTransport draft.

## What

Implements W3C WebTransport on top of HTTP/3 (QUIC). Surface: bidirectional + unidirectional streams (reliable, ordered) + datagrams (unreliable, unordered, low-latency). The combination matches `WebRTC` for low-latency multimedia + game traffic but retains the simpler firewall + session model of `pulsar-websocket`. Pulsar wires the WebTransport handshake on top of the same `pulsar-http` HTTP/3 listener used for normal HTTP/3 traffic.

## Why

WebTransport closes the gap WebSocket + SSE leave open: low-latency unreliable datagrams (for game state, telemetry) without the complexity of full WebRTC stack. Browser support landed in Chrome + Edge in 2024 + Firefox in 2025 + Safari in 2026 — by GA the addressable browser surface is universal. Pulsar ships WebTransport as a first-class realtime sub-protocol so apps that need low-latency unreliable traffic do not reach for WebRTC.

## How

`pulsar-webtransport` is a v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51. Depends on `pulsar-http` for the HTTP/3 + QUIC transport (which itself wraps `quinn`), `pulsar-kernel` for capability gating on the session. The lifecycle state machine is similar to `pulsar-websocket` but the per-stream + per-datagram backpressure semantics are distinct (datagrams have no flow-control by design).

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3B.2 (historic `pulsar-realtime` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.38.3 + Section V.3B.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
