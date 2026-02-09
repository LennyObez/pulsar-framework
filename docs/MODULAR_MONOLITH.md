# Modular Monolith Architecture

Pulsar follows a **Modular Monolith** architecture combining **Vertical Slices** and **Ports/Adapters** patterns. This guide explains the conventions, directory structure, and migration path for extensions.

## Core Principles

1. **Module boundaries are directory-enforced.** Each extension is a self-contained module with explicit public and private surfaces.
2. **Public API is opt-in.** Only types marked with `#[Api]` are part of the extension's public contract. Everything else is internal by default.
3. **Vertical slices organize business logic.** Each use case lives in its own slice with a Handler, Request DTO, and Result DTO.
4. **Ports define contracts; adapters implement them.** Interfaces live in `Contracts/`, implementations live in `Internal/Infrastructure/`.
5. **No hierarchical dispatch.** The framework uses single-level Route-to-Handler-to-Response dispatch. This is a hard rule.

## Directory Convention

```
extensions/<name>/src/
├── <Name>Extension.php            # Extension entry point
├── <Name>ServiceProvider.php      # Container bindings
│
├── Contracts/                     # PUBLIC API - interfaces marked #[Api]
│   ├── <Port>Interface.php        # Port definitions
│   └── ...
│
├── Domain/                        # PUBLIC - value objects, entities, enums
│   └── ...
│
├── Config/                        # PUBLIC - configuration DTOs
│   └── ...
│
├── Exception/                     # PUBLIC - exception classes
│   └── ...
│
├── Features/                      # VERTICAL SLICES - internal by default
│   ├── <UseCaseName>/
│   │   ├── <UseCaseName>Handler.php
│   │   ├── <UseCaseName>Request.php
│   │   ├── <UseCaseName>Result.php
│   │   └── (optional controller)
│   └── ...
│
├── Internal/                      # PRIVATE - implementation details
│   ├── Infrastructure/            # Adapter implementations
│   │   ├── Provider/
│   │   ├── Clock/
│   │   └── ...
│   └── Support/                   # Internal utilities
│       └── ...
│
├── Gateway/                       # Orchestrators (facade over slices)
│   └── <Name>Gateway.php
│
└── Webhook/                       # Public DTOs + orchestrators
    ├── WebhookProcessor.php
    └── (claim DTOs)
```

## Visibility Rules

| Directory    | Visibility    | Attribute             | Consumer Access   |
| ------------ | ------------- | --------------------- | ----------------- |
| `Contracts/` | Public        | `#[Api]`              | Import freely     |
| `Domain/`    | Public        | `#[Api]`              | Import freely     |
| `Config/`    | Public        | `#[Api]`              | Import freely     |
| `Exception/` | Public        | `#[Api]`              | Import freely     |
| `Features/`  | Internal      | None                  | Do not import     |
| `Internal/`  | Private       | `#[Internal]`         | Do not import     |
| `Gateway/`   | Public facade | `#[Api]` on interface | Use via interface |

**Rule:** External consumers must only depend on `Contracts/` interfaces and `Domain/` types. Never depend on `Internal/` or `Features/` directly.

## Port/Adapter Pattern

### Ports (Contracts/)

Ports are interfaces that define what the module needs from the outside world or what it offers to consumers:

```php
// Contracts/PaymentProviderInterface.php
#[Api]
interface PaymentProviderInterface
{
    public function createIntent(Money $amount, array $metadata = []): PaymentIntent;
}
```

### Adapters (Internal/Infrastructure/)

Adapters implement ports with concrete technology choices:

```php
// Internal/Infrastructure/Provider/NullProvider.php
#[Internal]
final readonly class NullProvider implements PaymentProviderInterface
{
    // Testing/development adapter
}
```

Consumers select adapters via configuration, not direct instantiation. The service provider resolves the correct adapter based on config values.

## Vertical Slices

Each slice encapsulates a single use case with three components:

### Request DTO

Immutable input for the use case:

```php
final readonly class CreatePaymentIntentRequest
{
    public function __construct(
        public Money $amount,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {}
}
```

### Handler

Orchestrates the use case logic. Receives dependencies via constructor injection:

```php
final readonly class CreatePaymentIntentHandler
{
    public function __construct(
        private PaymentProviderInterface $provider,
        private IdempotencyStoreInterface $idempotencyStore,
        // ... other dependencies
    ) {}

    public function execute(CreatePaymentIntentRequest $request): CreatePaymentIntentResult
    {
        // Full orchestration: validate, claim idempotency, call provider, record metrics
    }
}
```

### Result DTO

Immutable output:

```php
final readonly class CreatePaymentIntentResult
{
    public function __construct(
        public PaymentIntent $intent,
        public bool $replayed = false,
    ) {}
}
```

### Slice Granularity

Extract into a slice when the use case has:

- Cross-cutting concerns (idempotency, metrics, audit logging)
- Multiple steps that form a pipeline
- Distinct request/response shapes

Simple property reads or trivial delegations can remain on the orchestrator (Gateway/Processor).

## Gateway Pattern

Gateways are public facades that delegate to slices. They implement a public interface from `Contracts/` and provide backward-compatible method signatures:

```php
final readonly class PaymentGateway implements PaymentGatewayInterface
{
    public function createIntent(Money $amount, string $key, array $metadata = []): PaymentIntent
    {
        return $this->createHandler->execute(
            new CreatePaymentIntentRequest($amount, $key, $metadata),
        )->intent;
    }
}
```

Consumers interact with `PaymentGatewayInterface`. The gateway orchestrates which slice handles each operation.

## Service Provider

The service provider wires all components:

```php
// Contracts → Adapters
$container->bind(PaymentProviderInterface::class, fn () => match ($config->provider) {
    'null' => new NullProvider($clock),
    'simulator' => new SimulatorProvider($clock),
    default => $container->get($config->provider),
});

// Slice handlers
$container->bind(CreatePaymentIntentHandler::class, CreatePaymentIntentHandler::class);

// Public facades
$container->bind(PaymentGatewayInterface::class, PaymentGateway::class);
```

## Migration Guide for Extensions

To migrate an existing extension to this architecture:

1. **Create `Contracts/`** - Move or create interfaces. Mark all with `#[Api]`.
2. **Create `Internal/Infrastructure/`** - Move adapter implementations. Mark with `#[Internal]`.
3. **Identify slices** - Find use cases with cross-cutting orchestration in gateways/processors.
4. **Extract slices** - Create `Features/<UseCaseName>/` with Handler + Request + Result.
5. **Update orchestrators** - Gateway/Processor delegates to handlers.
6. **Update service provider** - Add bindings for new handlers, interfaces, and adapters.
7. **Update tests** - Create handler-level tests, update imports.

## Exemplar

The Payments extension (`extensions/payments/`) is the reference implementation of this architecture. Study its structure for conventions and patterns.
