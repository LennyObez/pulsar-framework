# Payments Extension

Vendor-agnostic payment processing for Pulsar applications. Provides a domain model, idempotency enforcement, webhook verification, and test providers without external SDK dependencies.

## Architecture

```
PaymentGateway (orchestrator)
├── PaymentProviderInterface (adapter)
├── IdempotencyStoreInterface (claim-based)
├── AuditLogger (tamper-evident)
├── MetricRegistry (observability)
└── ClockInterface (testable time)
```

The gateway wraps a thin provider adapter with cross-cutting concerns: idempotency enforcement, audit logging, and metrics collection. Vendor-specific adapters implement `PaymentProviderInterface` and remain isolated in the adapter boundary.

## Quick Start

### Configuration

```php
// config/payments.php
return [
    'provider'         => 'null',       // 'null', 'simulator', or a class-string
    'default_currency' => 'USD',
    'webhook' => [
        'secret'             => '',     // env: PAYMENTS_WEBHOOK_SECRET
        'path'               => '/webhooks/payments',
        'tolerance_seconds'  => 300,
        'signature_header'   => 'X-Payments-Signature',
    ],
    'idempotency' => [
        'ttl_seconds'   => 86400,
        'store'         => 'memory',    // 'memory' or a class-string
        'max_key_length' => 256,
    ],
    'webhook_log' => [
        'ttl_seconds' => 259200,
        'store'       => 'memory',
    ],
];
```

### Creating a Payment

```php
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Gateway\PaymentGateway;

$gateway = $container->get(PaymentGateway::class);

$amount = Money::of(2500, Currency::USD);  // $25.00
$intent = $gateway->createIntent($amount, 'order-123-intent');
$charge = $gateway->captureIntent($intent->id, 'order-123-capture');
```

### Processing Refunds

```php
// Full refund
$refund = $gateway->refund($charge->id, null, 'order-123-refund');

// Partial refund
$partial = Money::of(500, Currency::USD);  // $5.00
$refund = $gateway->refund($charge->id, $partial, 'order-123-partial');
```

## Money

`Money` stores amounts as integer minor units (e.g., cents for USD, no minor units for JPY) paired with an ISO 4217 `Currency` enum.

- No floating-point arithmetic anywhere
- Cross-currency operations are rejected at the type level
- Allocation distributes remainder one unit at a time (no pennies lost)
- Formatting respects currency minor digits automatically

```php
$price = Money::of(1050, Currency::USD);    // $10.50
$tax   = $price->percentage(875);           // 8.75% → $0.92
$total = $price->add($tax);                 // $11.42
$split = $total->allocate(3);               // [$3.81, $3.81, $3.80]
```

## Idempotency

Every mutating gateway operation requires an idempotency key. The gateway enforces a claim-based protocol:

1. **Claim**: Atomically reserve the key with a canonical parameters hash
2. **Execute**: Delegate to the payment provider
3. **Commit**: Store the result payload on success
4. **Release**: Free the key on provider failure (allows retry)

### Claim Semantics

| Scenario                        | Result    | Action                        |
| ------------------------------- | --------- | ----------------------------- |
| New key                         | Claimed   | Execute provider, then commit |
| Same key + same parameters hash | Replay    | Return cached result          |
| Same key + different hash       | Mismatch  | Throw `IdempotencyException`  |
| Same key + in-flight (Fiber)    | Exception | Throw concurrent claim        |

### Key Requirements

- ASCII printable characters only (0x21-0x7E)
- 1-256 characters (configurable max)
- No whitespace or control characters

### Parameters Hash

The gateway computes a canonical SHA-256 hash of operation parameters to detect misuse of idempotency keys. Metadata is explicitly excluded from the hash — contextual information should not cause a mismatch.

### Commit Failure Policy

If the provider succeeds but the idempotency store fails to commit, the gateway logs a critical error and rethrows. The key remains in-flight until TTL expiry, preventing double-charging at the cost of temporary unavailability for that key.

For production environments with unstable stores, use a shorter idempotency TTL (5-15 minutes) to limit the dead-key window.

## Webhook Verification

### Signature Format

Header: `X-Payments-Signature`

```
t=1700000000,v1=a1b2c3d4...
```

- `t`: Unix timestamp (integer)
- `v1`: HMAC-SHA256 hex signature
- Multiple `v1=` values allowed for secret rotation

### Verification Steps

1. Parse header into timestamp and signature candidates
2. Compute expected HMAC: `HMAC-SHA256(key=secret, message="{t}.{raw_body}")`
3. Compare each candidate with constant-time `hash_equals()`
4. Accept if any candidate matches
5. Reject if timestamp exceeds tolerance (default 300s)

### Replay Prevention

The webhook processor uses a separate event log with claim-based deduplication:

1. Verify signature
2. Parse event from raw body
3. Claim event ID (returns Replay for already-processed events)
4. Dispatch to handler
5. Commit on success, release on failure

Failed handler dispatches release the event ID, allowing vendor retry. Only successfully processed events are committed to the replay log.

## Test Providers

### NullProvider

No-op provider where all operations succeed immediately. Useful for unit tests and development environments.

### SimulatorProvider

Deterministic test vector provider for integration testing:

| Amount (minor units) | Behavior                            |
| -------------------- | ----------------------------------- |
| Most amounts         | Success                             |
| 9999                 | Decline: insufficient_funds         |
| 9998                 | Decline: card_expired               |
| 9997                 | Decline: card_declined              |
| 9996                 | Decline: processing_error           |
| 9995                 | Decline: fraud_suspected            |
| 9994                 | Timeout exception                   |
| 9993                 | Network error exception             |
| 9992                 | Rate limited exception              |
| 4242                 | Success, then dispute after capture |
| 3030                 | Success, but refund fails           |

IDs are deterministic: `SHA256(provider:operation:idempotencyKey:resourceId)[:32]`.

## Creating Vendor Adapters

See `extensions/payments/src/Adapter/README.md` for a guide on implementing vendor-specific adapters.

## Metrics

The gateway registers the following counters on `MetricRegistry`:

| Metric                                  | Labels                          |
| --------------------------------------- | ------------------------------- |
| `payments_intents_total`                | provider, currency, status      |
| `payments_captures_total`               | provider, currency, status      |
| `payments_refunds_total`                | provider, currency, status      |
| `payments_provider_errors_total`        | provider, operation, error_type |
| `payments_idempotency_replays_total`    | provider, operation             |
| `payments_webhooks_total`               | provider, event_type, status    |
| `payments_webhook_handler_errors_total` | provider, event_type            |

## Audit Logging

Every mutating operation writes a tamper-evident audit entry via `AuditLogger`. Audit metadata includes:

- `provider`, `currency`, `amount_minor_units`, `status`
- Never includes card numbers, tokens, or PII

## Security Considerations

- All signature comparisons use `hash_equals()` (constant-time)
- Raw request body bytes are verified — no transformation before HMAC
- Webhook secrets should be loaded from environment variables
- Idempotency keys prevent replay attacks on mutating operations
- The in-memory stores use Fiber-safe mutexes to prevent concurrent claim races
