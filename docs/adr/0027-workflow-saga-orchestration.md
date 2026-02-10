# ADR-0027: Workflow & Saga Orchestration

- **Status**: Accepted
- **Date**: 2026-03-02
- **Plan**: RC11-20

## Context

Regulated domains (banking, healthcare, legal) have complex multi-step processes: loan approvals, insurance claims, patient referrals, compliance reviews. These require durable, auditable state management with strict concurrency control, compensation semantics, and compliance-grade audit trails.

Spiral integrates Temporal for durable workflows. Symfony provides a Workflow component for state machines. Neither offers the combination of compensation orchestration, classified context storage, and outbox-mandatory integration events that regulated domains require.

Pulsar needs two complementary abstractions:

1. **Workflow engine** — state machine definitions with guard-protected transitions, optimistic locking, and append-only transition logs for auditability.
2. **Saga orchestrator** — multi-step processes with forward/compensation actions, idempotency keys, retry policies, and irreversibility tracking. Communication via events and the transactional outbox pattern (no RPC, no two-phase commit).

## Decision

### Two separate modules

`src/Workflow/` handles state machine definitions and transition enforcement. `src/Saga/` handles saga orchestration with compensation logic. Both share the `workflow_instances` table for instance lifecycle (a saga IS a specialized workflow instance) and the `workflow_transitions` table for append-only audit. The Saga module adds a dedicated `saga_step_results` table for step-level execution tracking: per-step status, idempotency keys used, compensation state, retry counts, and step-level error details. This avoids overloading the JSON `context` column with execution machinery.

### Optimistic locking with compare-and-swap

Workflow instance state is protected by optimistic locking. Every instance stores a `version` number. Transitions use CAS semantics: `UPDATE ... SET state = ?, version = version + 1 WHERE id = ? AND version = ?`. A version mismatch throws `ConcurrentTransitionException`. This avoids pessimistic lock contention while ensuring consistency.

### Append-only transition log

Every transition is recorded as an immutable entry in `workflow_transitions`. The table is never updated or deleted. Current state can always be reconstructed from the transition log. This serves as the audit trail.

### Compensation semantics (not rollback)

Saga compensation is fundamentally different from database rollback. Each step declares: forward action, compensation action, idempotency keys for both, retry policies for both, and an irreversibility flag. Compensation executes in reverse order, skipping irreversible steps. When a saga fails after irreversible steps, an `IrreversibleSagaFailureEvent` is emitted requiring operator acknowledgment.

### Outbox-mandatory integration events

Distributed sagas communicate via events, not synchronous RPC. State changes and outbox event writes happen atomically in the same database transaction. Direct publication to `IntegrationEventBusPort` is forbidden in saga step handlers — enforced at build time (PHPStan rule) and runtime (container guard). The outbox relay publishes events asynchronously after commit.

### Definition versioning

Every workflow/saga instance stores `definition_id` and `definition_version` at creation time. In-flight instances continue on their original definition version. New instances use the latest published version. Explicit migration between versions is opt-in via `DefinitionMigrator`, never automatic. Old definition versions are retained in the `DefinitionVersionRegistry` for auditability.

### Classified context storage with automatic encryption

`workflow_instances.context` (JSON) supports field-level classification tags. The classification level drives encryption policy automatically — no separate opt-in:

- **Public**: stored in plaintext, included in exports and logs
- **Internal**: stored in plaintext, redacted from public exports
- **Restricted**: encrypted at rest via Keyring service (Finding B), redacted from all exports
- **Pii**: encrypted at rest via Keyring service (Finding B), redacted from all exports, subject to retention limits

In regulated presets, unclassified fields are forbidden (validation on write). Encryption uses the Keyring's domain-separated key derivation with context `workflow-context` as the KDF domain. Retention rules are configurable per classification level.

### Guards with structured ActorContext

Transition guards receive a structured `ActorContext` (subject ID, tenant ID, claims snapshot ID) and must be deterministic. Guard evaluation results (allow/deny with reason) are logged for audit compliance.

### Timeout handling via port interface

Timeout detection uses a `TimeoutHandlerInterface` port with two methods: `scheduleTimeout(instanceId, duration)` and `cancelTimeout(instanceId)`. The default `PollingTimeoutHandler` queries for expired instances via a scheduled command (`workflow:check-timeouts`). Users with queue infrastructure provide their own implementation (e.g., delayed queue messages) via dependency injection. This avoids imposing infrastructure requirements while enabling efficient timeout handling for users who have the infrastructure.

### Saga step results table

The `saga_step_results` table tracks per-step execution state separately from the workflow instance:

| Column            | Type         | Description                                  |
| ----------------- | ------------ | -------------------------------------------- |
| `id`              | UUID         | Primary key                                  |
| `instance_id`     | UUID         | FK to `workflow_instances`                   |
| `step_name`       | VARCHAR(255) | Step identifier from saga definition         |
| `step_index`      | INT          | Execution order position                     |
| `direction`       | ENUM         | forward, compensating                        |
| `status`          | ENUM         | pending, running, completed, failed, skipped |
| `idempotency_key` | VARCHAR(255) | Key used for this execution (nullable)       |
| `attempts`        | INT          | Number of attempts made                      |
| `result_data`     | JSON         | Step output data (nullable)                  |
| `error_message`   | TEXT         | Error details on failure (nullable)          |
| `started_at`      | TIMESTAMP    | Step execution start                         |
| `completed_at`    | TIMESTAMP    | Step completion (nullable)                   |

This table is append-only for forward execution. During compensation, new rows are inserted with `direction = compensating`. Steps marked `irreversible` get a `skipped` status row during compensation.

## Consequences

- Two modules to maintain, but clear separation of concerns (state machine vs compensation orchestration)
- Optimistic locking means callers must handle `ConcurrentTransitionException` (retry logic at call site)
- Append-only transition log grows unboundedly — archival/compaction is the operator's responsibility
- Outbox enforcement adds a PHPStan rule and optional runtime guard — marginal build-time cost
- Definition versioning prevents automatic migration — operators must explicitly opt in for in-flight instance migration
- Classified context adds validation overhead on every context write in regulated presets

## Alternatives considered

- **Single module**: Rejected. Workflows and sagas serve different purposes; coupling them reduces usability for simple state machine use cases.
- **Pessimistic locking (SELECT FOR UPDATE)**: Rejected. Higher lock contention under concurrent transitions. CAS is standard for workflow engines.
- **Event sourcing for state**: Rejected. Append-only transition log provides auditability without the full complexity of event sourcing (projections, snapshots, replay). Can be added later if needed.
- **Automatic definition migration**: Rejected. Unsafe in regulated domains where in-flight instances must complete on their original rules.
- **Direct event publishing (no outbox)**: Rejected. Dual-write problem makes eventual consistency guarantees impossible without the outbox pattern.
- **Saga step state in JSON context column**: Rejected. Step-level execution tracking (attempts, idempotency keys, compensation state) is structured relational data that benefits from indexing and querying. A dedicated `saga_step_results` table is cleaner than overloading the JSON context.
- **Opt-in context encryption**: Rejected. Classification-driven automatic encryption is more secure — developers cannot forget to enable encryption for sensitive fields. The classification level IS the encryption policy.
- **Configurable compensation ordering**: Rejected. Reverse order is the universally accepted standard for saga compensation. Adding configurability introduces complexity with no demonstrated requirement.
- **Queue-based timeout as default**: Rejected. Would add Plan 13 (Queue) as a hard dependency. The polling-based default is portable; queue-based implementations are available via the port interface.
