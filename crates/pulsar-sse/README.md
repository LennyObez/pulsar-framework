# pulsar-sse

Server-Sent Events (SSE) push channel + reconnection state per WHATWG EventSource specification.

## What

Implements the WHATWG SSE protocol (`text/event-stream`) on top of `pulsar-http`: per-event `id` + `retry` + `event` + `data` field emission, automatic reconnection support via the `Last-Event-ID` request header, and stream multiplexing so a single TCP connection can carry events for multiple subscriptions. Companion to `pulsar-websocket` — SSE for one-way server-push when bidirectional WebSocket is not needed; lower overhead, simpler proxying through restrictive corporate firewalls.

## Why

SSE is the standards-tracked alternative to WebSocket for the common case "server pushes to client; client does not push back". Browsers ship native `EventSource` so no client library is required. Many regulated-environment proxies block WebSocket but allow SSE because SSE looks like a long-lived `text/event-stream` HTTP/1.1 + HTTP/2 GET — easier compliance with proxy + WAF policies than the WebSocket upgrade handshake.

## How

`pulsar-sse` is a v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51. It depends on `pulsar-http` for the HTTP/1.1 chunked + HTTP/2 stream framing, `pulsar-kernel` for capability gating on the subscription token. Backpressure handled identically to `pulsar-websocket` (bounded channel between source and write loop).

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3B.2 (historic `pulsar-realtime` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.38.2 + Section V.3B.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
