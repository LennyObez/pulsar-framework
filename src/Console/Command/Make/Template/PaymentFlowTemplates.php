<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make\Template;

/**
 * Template generator for make:payment-flow golden-path scaffolding.
 */
final readonly class PaymentFlowTemplates
{
    public function providerInterface(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Contracts;

            use Pulsar\\Api\\Api;
            use $namespace\\Domain\\{$name}Charge;
            use $namespace\\Domain\\{$name}Intent;
            use $namespace\\Domain\\{$name}Refund;
            use $namespace\\Domain\\Money;

            /**
             * Payment provider contract for $name flows.
             */
            #[Api(since: '1.0.0')]
            interface {$name}ProviderInterface
            {
                public function createIntent(Money \$amount, string \$currency): {$name}Intent;

                public function captureIntent(string \$intentId): {$name}Charge;

                public function refund(string \$chargeId, Money \$amount): {$name}Refund;
            }
            PHP;
    }

    public function nullProvider(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Internal\\Infrastructure;

            use Override;
            use $namespace\\Contracts\\{$name}ProviderInterface;
            use $namespace\\Domain\\{$name}Charge;
            use $namespace\\Domain\\{$name}ChargeStatus;
            use $namespace\\Domain\\{$name}Intent;
            use $namespace\\Domain\\{$name}IntentStatus;
            use $namespace\\Domain\\{$name}Refund;
            use $namespace\\Domain\\{$name}RefundStatus;
            use $namespace\\Domain\\Money;

            /**
             * Null-object provider for testing and development.
             */
            final readonly class Null{$name}Provider implements {$name}ProviderInterface
            {
                #[Override]
                public function createIntent(Money \$amount, string \$currency): {$name}Intent
                {
                    return new {$name}Intent(
                        id: 'intent_null_' . bin2hex(random_bytes(8)),
                        status: {$name}IntentStatus::Pending,
                        amount: \$amount,
                        currency: \$currency,
                    );
                }

                #[Override]
                public function captureIntent(string \$intentId): {$name}Charge
                {
                    return new {$name}Charge(
                        id: 'charge_null_' . bin2hex(random_bytes(8)),
                        intentId: \$intentId,
                        status: {$name}ChargeStatus::Succeeded,
                    );
                }

                #[Override]
                public function refund(string \$chargeId, Money \$amount): {$name}Refund
                {
                    return new {$name}Refund(
                        id: 'refund_null_' . bin2hex(random_bytes(8)),
                        chargeId: \$chargeId,
                        status: {$name}RefundStatus::Succeeded,
                        amount: \$amount,
                    );
                }
            }
            PHP;
    }

    public function gateway(string $name, string $namespace): string
    {
        $lcName = lcfirst($name);
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Gateway;

            use DateTimeImmutable;
            use Psr\\Clock\\ClockInterface;
            use Psr\\Log\\LoggerInterface;
            use Pulsar\\Idempotency\\IdempotencyClaimStatus;
            use Pulsar\\Idempotency\\IdempotencyStoreInterface;
            use Pulsar\\Observability\\Metrics\\MetricRegistry;
            use Pulsar\\Security\\Audit\\AuditLogger;
            use $namespace\\Config\\{$name}Config;
            use $namespace\\Contracts\\{$name}ProviderInterface;
            use $namespace\\Domain\\{$name}Intent;
            use $namespace\\Domain\\Money;
            use $namespace\\Exception\\{$name}Exception;

            use function json_decode;
            use function json_encode;

            use const JSON_THROW_ON_ERROR;

            /**
             * Payment gateway orchestrator for $name flows.
             *
             * Handles idempotency, audit logging, and metrics.
             */
            final readonly class {$name}Gateway
            {
                public function __construct(
                    private {$name}ProviderInterface \$provider,
                    private IdempotencyStoreInterface \$idempotencyStore,
                    private AuditLogger \$auditLogger,
                    private MetricRegistry \$metricRegistry,
                    private LoggerInterface \$logger,
                    private ClockInterface \$clock,
                    private {$name}Config \$config,
                ) {}

                /**
                 * Create a payment intent with idempotency protection.
                 */
                public function createIntent(
                    string \$idempotencyKey,
                    Money \$amount,
                    string \$currency,
                ): {$name}Intent {
                    \$parametersHash = ParametersHasher::hash([
                        'amount' => \$amount->amount,
                        'currency' => \$currency,
                    ]);

                    \$now = \$this->clock->now();

                    \$claim = \$this->idempotencyStore->claim(
                        \$idempotencyKey,
                        \$parametersHash,
                        'create_intent',
                        \$now,
                        \$this->config->idempotencyTtlSeconds,
                    );

                    if (\$claim->status === IdempotencyClaimStatus::Replay && \$claim->resultPayload !== null) {
                        /** @var array<string, mixed> \$cached */
                        \$cached = json_decode(\$claim->resultPayload, true, flags: JSON_THROW_ON_ERROR);
                        return {$name}Intent::fromArray(\$cached);
                    }

                    if (\$claim->status === IdempotencyClaimStatus::Mismatch) {
                        throw {$name}Exception::idempotencyMismatch(\$idempotencyKey);
                    }

                    try {
                        \$intent = \$this->provider->createIntent(\$amount, \$currency);

                        \$this->idempotencyStore->commit(
                            \$idempotencyKey,
                            json_encode(\$intent->toArray(), JSON_THROW_ON_ERROR),
                        );

                        \$this->auditLogger->log('$lcName.intent.created', [
                            'intent_id' => \$intent->id,
                            'amount' => \$amount->amount,
                            'currency' => \$currency,
                        ]);

                        \$this->metricRegistry->counter('{$lcName}_intents_created_total')->increment();

                        return \$intent;
                    } catch (\\Throwable \$e) {
                        \$this->idempotencyStore->release(\$idempotencyKey);
                        \$this->logger->error('Failed to create $lcName intent', [
                            'error' => \$e->getMessage(),
                        ]);

                        throw \$e;
                    }
                }
            }
            PHP;
    }

    public function parametersHasher(string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Gateway;

            use function json_encode;

            use const JSON_THROW_ON_ERROR;

            use NoDiscard;

            /**
             * Deterministic parameter hasher for idempotency checks.
             */
            final readonly class ParametersHasher
            {
                /**
                 * @param array<string, mixed> \$parameters
                 */
                #[NoDiscard]
                public static function hash(array \$parameters): string
                {
                    \$json = json_encode(\$parameters, JSON_THROW_ON_ERROR);

                    return hash('sha256', \$json);
                }
            }
            PHP;
    }

    public function config(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Config;

            use NoDiscard;
            use Pulsar\\Api\\Api;

            /**
             * Configuration DTO for $name payment flows.
             */
            #[Api(since: '1.0.0')]
            final readonly class {$name}Config
            {
                public function __construct(
                    public string \$provider = 'null',
                    public string \$defaultCurrency = 'USD',
                    public int \$idempotencyTtlSeconds = 86_400,
                ) {}

                /**
                 * @param array<string, mixed> \$data
                 */
                #[NoDiscard]
                public static function fromArray(array \$data): self
                {
                    return new self(
                        provider: (string) (\$data['provider'] ?? 'null'),
                        defaultCurrency: (string) (\$data['default_currency'] ?? 'USD'),
                        idempotencyTtlSeconds: (int) (\$data['idempotency_ttl_seconds'] ?? 86_400),
                    );
                }
            }
            PHP;
    }

    public function intent(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            use NoDiscard;

            final readonly class {$name}Intent
            {
                public function __construct(
                    public string \$id,
                    public {$name}IntentStatus \$status,
                    public Money \$amount,
                    public string \$currency,
                ) {}

                /**
                 * @return array<string, mixed>
                 */
                public function toArray(): array
                {
                    return [
                        'id' => \$this->id,
                        'status' => \$this->status->value,
                        'amount' => \$this->amount->amount,
                        'currency' => \$this->currency,
                    ];
                }

                /**
                 * @param array<string, mixed> \$data
                 */
                #[NoDiscard]
                public static function fromArray(array \$data): self
                {
                    return new self(
                        id: (string) (\$data['id'] ?? ''),
                        status: {$name}IntentStatus::from((string) (\$data['status'] ?? 'pending')),
                        amount: new Money((int) (\$data['amount'] ?? 0)),
                        currency: (string) (\$data['currency'] ?? 'USD'),
                    );
                }
            }
            PHP;
    }

    public function intentStatus(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            enum {$name}IntentStatus: string
            {
                case Pending = 'pending';
                case Captured = 'captured';
                case Cancelled = 'cancelled';
            }
            PHP;
    }

    public function charge(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            final readonly class {$name}Charge
            {
                public function __construct(
                    public string \$id,
                    public string \$intentId,
                    public {$name}ChargeStatus \$status,
                ) {}
            }
            PHP;
    }

    public function chargeStatus(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            enum {$name}ChargeStatus: string
            {
                case Succeeded = 'succeeded';
                case Failed = 'failed';
            }
            PHP;
    }

    public function refund(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            final readonly class {$name}Refund
            {
                public function __construct(
                    public string \$id,
                    public string \$chargeId,
                    public {$name}RefundStatus \$status,
                    public Money \$amount,
                ) {}
            }
            PHP;
    }

    public function refundStatus(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            enum {$name}RefundStatus: string
            {
                case Succeeded = 'succeeded';
                case Failed = 'failed';
                case Pending = 'pending';
            }
            PHP;
    }

    public function money(string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            /**
             * Value object representing a monetary amount in minor units (cents).
             */
            final readonly class Money
            {
                public function __construct(
                    public int \$amount,
                ) {}
            }
            PHP;
    }

    public function exception(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Exception;

            use NoDiscard;
            use Pulsar\\Api\\Api;
            use RuntimeException;

            use function sprintf;

            /**
             * $name payment flow exceptions.
             */
            #[Api(since: '1.0.0')]
            #[\\NoDiscard]
            final class {$name}Exception extends RuntimeException
            {
                #[NoDiscard]
                public static function providerFailed(string \$reason): self
                {
                    return new self(sprintf('$name provider failed: %s', \$reason));
                }

                #[NoDiscard]
                public static function idempotencyMismatch(string \$key): self
                {
                    return new self(sprintf(
                        'Idempotency key "%s" was previously used with different parameters',
                        \$key,
                    ));
                }
            }
            PHP;
    }

    public function providerException(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Exception;

            use NoDiscard;
            use RuntimeException;

            use function sprintf;

            /**
             * Provider-specific exceptions for $name flows.
             */
            final class {$name}ProviderException extends RuntimeException
            {
                #[NoDiscard]
                public static function unavailable(string \$reason): self
                {
                    return new self(sprintf('$name provider unavailable: %s', \$reason));
                }

                #[NoDiscard]
                public static function rejected(string \$reason): self
                {
                    return new self(sprintf('$name provider rejected request: %s', \$reason));
                }
            }
            PHP;
    }

    public function serviceProvider(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace;

            use Pulsar\\Container\\ContainerInterface;
            use Pulsar\\Extensibility\\ServiceProviderInterface;
            use $namespace\\Contracts\\{$name}ProviderInterface;
            use $namespace\\Internal\\Infrastructure\\Null{$name}Provider;

            final class {$name}ServiceProvider implements ServiceProviderInterface
            {
                public function register(ContainerInterface \$container): void
                {
                    \$container->bind({$name}ProviderInterface::class, Null{$name}Provider::class);
                }

                public function provides(): array
                {
                    return [
                        {$name}ProviderInterface::class,
                    ];
                }
            }
            PHP;
    }

    public function readme(string $name): string
    {
        return <<<MD
            # $name Payment Flow

            ## Structure

            - `Contracts/`: Public API interfaces (`#[Api(since: '1.0.0')]`)
            - `Internal/Infrastructure/`: Provider implementations
            - `Gateway/`: Payment orchestration with idempotency
            - `Config/`: Configuration DTOs
            - `Domain/`: Value objects and enums
            - `Exception/`: Domain exceptions
            MD;
    }

    public function gatewayTest(string $name, string $module, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$module\\Gateway;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use Psr\\Clock\\ClockInterface;
            use Psr\\Log\\NullLogger;
            use Pulsar\\Idempotency\\IdempotencyClaim;
            use Pulsar\\Idempotency\\IdempotencyStoreInterface;
            use Pulsar\\Observability\\Metrics\\MetricRegistry;
            use Pulsar\\Security\\Audit\\AuditLogger;
            use $namespace\\Config\\{$name}Config;
            use $namespace\\Contracts\\{$name}ProviderInterface;
            use $namespace\\Domain\\{$name}Intent;
            use $namespace\\Domain\\{$name}IntentStatus;
            use $namespace\\Domain\\Money;
            use $namespace\\Gateway\\{$name}Gateway;

            use DateTimeImmutable;

            #[CoversClass({$name}Gateway::class)]
            final class {$name}GatewayTest extends TestCase
            {
                #[Test]
                public function it_creates_intent_with_idempotency(): void
                {
                    \$provider = \$this->createMock({$name}ProviderInterface::class);
                    \$provider->method('createIntent')->willReturn(
                        new {$name}Intent('intent_001', {$name}IntentStatus::Pending, new Money(1000), 'USD'),
                    );

                    \$store = \$this->createMock(IdempotencyStoreInterface::class);
                    \$store->method('claim')->willReturn(IdempotencyClaim::claimed());

                    \$clock = \$this->createStub(ClockInterface::class);
                    \$clock->method('now')->willReturn(new DateTimeImmutable());

                    \$gateway = new {$name}Gateway(
                        \$provider,
                        \$store,
                        \$this->createStub(AuditLogger::class),
                        \$this->createStub(MetricRegistry::class),
                        new NullLogger(),
                        \$clock,
                        new {$name}Config(),
                    );

                    \$intent = \$gateway->createIntent('key_001', new Money(1000), 'USD');

                    self::assertSame('intent_001', \$intent->id);
                }
            }
            PHP;
    }
}
