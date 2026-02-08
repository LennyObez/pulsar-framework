# DX Commands Reference

Pulsar ships CLI scaffolding commands that generate boundary-compliant module structures with `Contracts/Internal` separation, config DTOs, observability wiring, and test stubs.

## Commands

### `make:module`

Generate a new module with Contracts/Internal separation.

```bash
php bin/pulsar make:module <name> [--path=app/Modules] [--with-config] [--with-tests]
```

**Generated structure:**

```
{Module}/
  Contracts/{Module}ServiceInterface.php       # #[Api] interface
  Internal/Infrastructure/{Module}Service.php  # implements interface
  Controller/{Module}Controller.php            # constructor-injected
  Config/{Module}Config.php                    # readonly DTO (--with-config)
  Middleware/ Models/ Views/                   # empty dirs
  ModuleServiceProvider.php                    # binds interface -> implementation
  routes.php
  README.md
```

### `make:feature`

Generate a vertical feature slice within a module.

```bash
php bin/pulsar make:feature <name> --module=<module> [--path=app/Modules] [--method=POST]
```

**Generated structure:**

```
{Module}/Features/{Feature}/
  Contracts/{Feature}HandlerInterface.php      # #[Api]
  {Feature}Handler.php                         # single handle() method
  {Feature}HandlerTest.php                     # test stub
```

Appends a route entry to the module's `routes.php`.

### `make:port`

Generate a port interface in a module's Contracts directory.

```bash
php bin/pulsar make:port <name> --module=<module> [--path=app/Modules] [--methods=process,refund]
```

**Generated structure:**

```
{Module}/Contracts/{Name}Interface.php         # #[Api], auto-suffixed
```

### `make:adapter`

Generate an adapter implementing a port interface.

```bash
php bin/pulsar make:adapter <name> --port=<port> --module=<module> [--path=app/Modules]
```

If the port interface file exists on disk, method signatures are parsed and stub implementations are generated with full type signatures.

**Generated structure:**

```
{Module}/Internal/Infrastructure/{Name}.php
tests/Unit/Modules/{Module}/Internal/Infrastructure/{Name}Test.php
```

### `make:webhook-handler`

Generate a webhook handler with verification, deduplication, and HTTP controller.

```bash
php bin/pulsar make:webhook-handler <name> --module=<module> [--path=app/Modules]
```

**Generated structure:**

```
{Module}/
  Contracts/{Name}WebhookHandlerInterface.php
  Internal/Infrastructure/
    {Name}WebhookHandler.php
    {Name}HmacVerifier.php
    InMemory{Name}EventLog.php
  Controller/{Name}WebhookController.php
  Config/{Name}WebhookConfig.php
  Domain/
    {Name}WebhookEvent.php
    {Name}WebhookEventType.php
```

Uses the core `Pulsar\Webhook\WebhookProcessor` for verify-deduplicate-dispatch orchestration.

### `make:payment-flow`

Generate a complete payment flow with idempotency, audit logging, and metrics.

```bash
php bin/pulsar make:payment-flow <name> --module=<module> [--path=app/Modules]
```

**Generated structure:**

```
{Module}/
  Contracts/{Name}ProviderInterface.php
  Internal/Infrastructure/Null{Name}Provider.php
  Gateway/{Name}Gateway.php
  Gateway/ParametersHasher.php
  Config/{Name}Config.php
  Domain/
    {Name}Intent.php, {Name}IntentStatus.php
    {Name}Charge.php, {Name}ChargeStatus.php
    {Name}Refund.php, {Name}RefundStatus.php
    Money.php
  Exception/{Name}Exception.php, {Name}ProviderException.php
  {Name}ServiceProvider.php
```

Uses `Pulsar\Idempotency\IdempotencyStoreInterface` for claim-or-replay protection.

### `make:event-ingestion`

Generate an event ingestion pipeline with webhook verification and deduplication.

```bash
php bin/pulsar make:event-ingestion <name> --module=<module> [--path=app/Modules] [--events=push,pull_request]
```

**Generated structure:**

```
{Module}/
  Contracts/{Name}EventHandlerInterface.php
  Internal/Infrastructure/
    {Name}EventHandler.php
    {Name}HmacVerifier.php
    InMemory{Name}EventLog.php
  Controller/{Name}WebhookController.php
  Config/{Name}IngestionConfig.php
  Domain/
    {Name}Event.php
    {Name}EventType.php
  Exception/{Name}IngestionException.php
  {Name}IngestionServiceProvider.php
```

The `--events` flag populates the backed enum with custom event types.

## Conventions

All generated code follows these conventions:

- **Contracts/** (plural) for public API interfaces marked with `#[Api]`
- **Internal/Infrastructure/** for implementation details
- **Controller/** (singular) for HTTP controllers
- Constructor injection only — no service locators
- Readonly DTOs with `fromArray()` factories for configuration
- Exception classes with static factory methods
- ServiceProviders bind interface to implementation
