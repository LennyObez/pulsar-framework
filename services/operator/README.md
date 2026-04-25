# Pulsar Kubernetes Operator

Go + kubebuilder operator per Decision 2.46. Declarative reconciliation of `Pulsar`, `PulsarExtension`, and `PulsarSecret` custom resources on Kubernetes clusters.

**Stack** : Go 1.23+, kubebuilder v4, controller-runtime, client-go, kustomize, operator-sdk.

**Custom Resources** :
- `Pulsar` — declarative deployment spec (replicas, postgres, etcd, patroni, ingress, TLS).
- `PulsarExtension` — install, configure, capability-grant a WASM extension.
- `PulsarSecret` — bind external secret stores to Pulsar config.

**Status** : placeholder for namespace reservation. Implementation follows Sprint planning in [`../../docs/plan.md`](../../docs/plan.md).

## Licence

Apache-2.0. See the workspace [LICENSE](../../LICENSE) file.
