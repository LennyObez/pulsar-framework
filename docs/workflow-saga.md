# Workflow & Saga Orchestration

Pulsar provides two complementary modules for managing complex, multi-step processes in regulated domains: a **Workflow Engine** for state machine enforcement and a **Saga Orchestrator** for distributed transaction coordination with compensation.

**ADR**: [ADR-0027](adr/0027-workflow-saga-orchestration.md)

## Workflow Engine

### Overview

The Workflow module (`Pulsar\Workflow`) provides state machine definitions with guard-protected transitions, optimistic locking, and append-only transition logs. It supports two modes:

- **StateMachine**: single active state per instance (most common)
- **Workflow**: multiple active states for parallel branches

### Defining a Workflow

Use the fluent `DefinitionBuilder`:

```php
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\StateType;
use Pulsar\Workflow\Definition\WorkflowType;

$definition = DefinitionBuilder::create('order_fulfillment', WorkflowType::StateMachine)
    ->addState('pending', StateType::Initial)
    ->addState('processing', StateType::Intermediate)
    ->addState('shipped', StateType::Intermediate)
    ->addState('delivered', StateType::Final)
    ->addState('cancelled', StateType::Final)
    ->addTransition('start_processing', ['pending'], 'processing')
    ->addTransition('ship', ['processing'], 'shipped')
    ->addTransition('deliver', ['shipped'], 'delivered')
    ->addTransition('cancel', ['pending', 'processing'], 'cancelled')
    ->build();
```

### Guards and ActorContext

Transition guards enforce authorization rules. Guards receive a structured `ActorContext` and must be deterministic:

```php
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Guard\RoleGuard;

// RoleGuard checks the actor's roles against transition metadata
$definition = DefinitionBuilder::create('approval', WorkflowType::StateMachine)
    ->addState('draft', StateType::Initial)
    ->addState('approved', StateType::Final)
    ->addTransition('approve', ['draft'], 'approved', [
        'guards' => [RoleGuard::class],
        'required_roles' => ['manager', 'admin'],
    ])
    ->build();

// ActorContext carries the actor's identity
$actor = new ActorContext(
    subjectId: 'user-123',
    tenantId: 'tenant-456',
    claimsSnapshotId: 'claims-789',
);
```

Built-in guards:

- **`RoleGuard`**: checks actor roles against `required_roles` in transition metadata
- **`ExpressionGuard`**: evaluates simple conditions on workflow context (`context:has`, `context:eq`, `context:neq`)

Guard evaluation results (allow/deny with reason) are logged for audit compliance.

### Using the Engine

```php
use Pulsar\Workflow\Engine\WorkflowEngineInterface;

// Start a workflow instance
$instance = $engine->start($definition, $actor, $initialContext);

// Apply a transition
$instance = $engine->apply($instance, 'start_processing', $actor, 'Order payment confirmed');

// Check available transitions
$enabled = $engine->getEnabledTransitions($instance, $actor);

// Check if a specific transition is possible
$canShip = $engine->can($instance, 'ship', $actor);
```

### Optimistic Locking

Concurrent transitions on the same instance are protected by compare-and-swap (CAS). If two processes attempt to transition the same instance simultaneously, one receives a `ConcurrentTransitionException`:

```php
use Pulsar\Workflow\Exception\ConcurrentTransitionException;

try {
    $instance = $engine->apply($instance, 'approve', $actor);
} catch (ConcurrentTransitionException $e) {
    // Reload instance and retry
    $instance = $storage->findById($e->instanceId);
    $instance = $engine->apply($instance, 'approve', $actor);
}
```

### Transition Events

Every transition dispatches events through the Event module (Plan 01):

- `WorkflowStartedEvent`: instance created
- `TransitionAppliedEvent`: transition completed
- `TransitionBlockedEvent`: guard denied the transition
- `WorkflowCompletedEvent`: instance reached a final state

### Definition Versioning

Workflow definitions evolve over time. The versioning system ensures safety:

- Every instance stores `definition_id` and `definition_version` at creation
- In-flight instances continue on their original definition version
- New instances always use the latest published version
- Explicit migration via `DefinitionMigratorInterface` is opt-in, never automatic
- Old versions are retained in `DefinitionVersionRegistryInterface` for auditability

### Classified Context

Workflow instance context supports field-level classification:

```php
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\ClassificationLevel;

$context = ClassifiedContext::empty()
    ->set('order_id', 'ORD-123', ClassificationLevel::Public)
    ->set('customer_name', 'Jane Doe', ClassificationLevel::Pii)
    ->set('payment_token', 'tok_xxx', ClassificationLevel::Restricted);

// Export with redaction (only Public and Internal fields)
$safe = $context->redactForExport(ClassificationLevel::Internal);
// Result: ['order_id' => 'ORD-123']
```

Classification levels and encryption behavior:

| Level      | Stored    | Exported | Encrypted at rest |
| :--------- | :-------- | :------- | :---------------- |
| Public     | Plaintext | Yes      | No                |
| Internal   | Plaintext | No       | No                |
| Restricted | Encrypted | No       | Yes (via Keyring) |
| Pii        | Encrypted | No       | Yes (via Keyring) |

In regulated presets, unclassified fields are forbidden.

### Timeout Handling

Workflow states can have timeouts. The `TimeoutHandlerInterface` port supports timeout scheduling:

- Default `PollingTimeoutHandler` queries for expired instances via scheduled command
- Users with queue infrastructure provide their own implementation via DI

### Visualization

Generate DOT (Graphviz) graphs from workflow definitions:

```php
use Pulsar\Workflow\Visualization\DotGraphExporterInterface;

$dot = $exporter->export($definition);
// Returns valid DOT string for Graphviz rendering

// Highlight current state for a running instance
$dot = $exporter->exportWithInstance($definition, $instance);
```

## Saga Orchestrator

### Overview

The Saga module (`Pulsar\Saga`) orchestrates multi-step processes with compensation semantics. When a step fails, previously completed steps are compensated in reverse order.

Compensation is **not** rollback: each step declares explicit compensation actions with their own idempotency keys and retry policies.

### Defining a Saga

```php
use Pulsar\Saga\SagaDefinitionBuilder;
use Pulsar\Saga\Step\BackoffStrategy;

$saga = SagaDefinitionBuilder::create('order_saga')
    ->step('reserve_inventory')
        ->forward(ReserveInventoryAction::class)
        ->forwardIdempotencyKey(fn($ctx) => "reserve-{$ctx['order_id']}")
        ->compensate(ReleaseInventoryAction::class)
        ->compensationIdempotencyKey(fn($ctx) => "release-{$ctx['order_id']}")
        ->retryPolicy(maxAttempts: 3, backoff: BackoffStrategy::Exponential)
    ->step('charge_payment')
        ->forward(ChargePaymentAction::class)
        ->forwardIdempotencyKey(fn($ctx) => "charge-{$ctx['order_id']}")
        ->compensate(RefundPaymentAction::class)
        ->compensationIdempotencyKey(fn($ctx) => "refund-{$ctx['order_id']}")
        ->retryPolicy(maxAttempts: 3, backoff: BackoffStrategy::Exponential)
        ->compensationRetryPolicy(maxAttempts: 5, backoff: BackoffStrategy::Exponential)
    ->step('send_confirmation')
        ->forward(SendConfirmationAction::class)
        ->irreversible() // Cannot un-send an email
    ->build();
```

### Executing a Saga

```php
use Pulsar\Saga\SagaOrchestratorInterface;

$state = $orchestrator->execute($saga, ['order_id' => 'ORD-123']);

// Resume a saga after process restart
$state = $orchestrator->resume($sagaId);

// Manually trigger compensation
$state = $orchestrator->compensate($sagaId);
```

### Irreversible Steps and Compliance Events

Steps marked `irreversible()` cannot be compensated. When a saga fails after irreversible steps complete, an `IrreversibleSagaFailureEvent` is emitted containing:

- Saga ID and definition
- List of completed irreversible steps
- Failed step and error details
- Required operator action hint

In regulated presets, this event requires operator acknowledgment.

### Durable Execution

Saga state is persisted after each step, enabling recovery after process restart. The `saga_step_results` table tracks per-step execution state including idempotency keys, attempt counts, and compensation progress.

### Distributed Sagas

Distributed sagas communicate via events, not synchronous RPC:

- **`OutboxPort`**: durable event emission via transactional outbox (atomic with state changes)
- **`CommandBusPort`**: dispatch commands to local or remote handlers
- **`IntegrationEventBusPort`**: publish integration events (forbidden in saga step handlers)

**Outbox enforcement**: Direct injection of `IntegrationEventBusPort` in saga step handlers is forbidden:

- **Build-time**: PHPStan rule detects forbidden injection in step handler constructors
- **Runtime**: `SagaContainerGuard` throws `ForbiddenInjectionException` on container resolution

### Saga Events

- `SagaStartedEvent`: saga execution begins
- `SagaStepCompletedEvent` / `SagaStepFailedEvent`: per-step progress
- `SagaCompensationStartedEvent` / `SagaCompensationCompletedEvent`: compensation lifecycle
- `SagaCompletedEvent` / `SagaFailedEvent`: saga outcome
- `IrreversibleSagaFailureEvent`: compliance event for irreversible failure

## Regulated Workflow Templates

Pre-built templates for common regulated-domain patterns:

### Approval Workflow

Submit → Review → Approve/Reject with optional escalation:

```php
use Pulsar\Workflow\Template\ApprovalWorkflow;

$definition = ApprovalWorkflow::create(
    name: 'loan_approval',
    approverRoles: ['loan_officer', 'senior_reviewer'],
    enableEscalation: true,
);
```

### Escalation Workflow

Multi-level escalation with configurable levels and roles:

```php
use Pulsar\Workflow\Template\EscalationWorkflow;

$definition = EscalationWorkflow::create(
    name: 'incident_escalation',
    levels: 3,
    rolesPerLevel: [
        1 => ['support_agent'],
        2 => ['team_lead'],
        3 => ['director'],
    ],
);
```

### Audit Workflow

Change → Review → Sign-off with mandatory reason tracking:

```php
use Pulsar\Workflow\Template\AuditWorkflow;

$definition = AuditWorkflow::create('config_change_audit');
```

### Compliance Review Workflow

Assess → Remediate → Verify → Certify with evidence requirements:

```php
use Pulsar\Workflow\Template\ComplianceReviewWorkflow;

$definition = ComplianceReviewWorkflow::create('hipaa_review');
```
