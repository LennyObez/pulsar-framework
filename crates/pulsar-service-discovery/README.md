# pulsar-service-discovery

Service discovery + health checking with DNS-SD, Consul, etcd, and Kubernetes Endpoints backends.

## What

Resolves logical service names ("payments-api", "auth-server") to live network endpoints with health-checking + load-balancing-aware metadata. Adapter set: DNS-SD (RFC 6763 SRV + TXT), Consul (HTTP API), etcd (gRPC), Kubernetes Endpoints (watch API). Each adapter feeds a unified registry that exposes a stream-based subscription so consumers react to topology changes in real time.

## Why

Distributed Pulsar deployments span multiple instances + multiple services + multiple regions. Hard-coding endpoints in configuration is brittle (any deployment topology change requires rolling restarts); using only DNS A records loses the per-endpoint health + metadata signal. Service discovery primitives close that gap with a single interface across the four backends most regulated environments standardise on.

## How

`pulsar-service-discovery` is a v2.3 dé-fusion of the v2.2 `pulsar-cluster` meta-crate per Decision 2.51. Each backend is an adapter behind the `Resolver` trait per Decision 2.22 hexagonal pattern. The active adapter is selected at composition root via configuration. Health checks integrate with `pulsar-resilience` for circuit-breaker propagation.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3E.4 (historic `pulsar-cluster` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.50.2 + Section V.3E.4.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
