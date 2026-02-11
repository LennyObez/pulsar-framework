<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\Template\PaymentFlowTemplates;

#[CoversClass(PaymentFlowTemplates::class)]
final class PaymentFlowTemplatesTest extends TestCase
{
    private PaymentFlowTemplates $templates;

    protected function setUp(): void
    {
        $this->templates = new PaymentFlowTemplates();
    }

    #[Test]
    public function providerInterfaceContainsPaymentMethods(): void
    {
        $output = $this->templates->providerInterface('Stripe', 'App\\Payment');

        self::assertStringContainsString('namespace App\\Payment\\Contracts;', $output);
        self::assertStringContainsString('interface StripeProviderInterface', $output);
        self::assertStringContainsString('public function createIntent(Money $amount, string $currency): StripeIntent;', $output);
        self::assertStringContainsString('public function captureIntent(string $intentId): StripeCharge;', $output);
        self::assertStringContainsString('public function refund(string $chargeId, Money $amount): StripeRefund;', $output);
    }

    #[Test]
    public function nullProviderImplementsAllMethods(): void
    {
        $output = $this->templates->nullProvider('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class NullStripeProvider implements StripeProviderInterface', $output);
        self::assertStringContainsString('StripeIntentStatus::Pending', $output);
        self::assertStringContainsString('StripeChargeStatus::Succeeded', $output);
        self::assertStringContainsString('StripeRefundStatus::Succeeded', $output);
        self::assertStringContainsString("'intent_null_'", $output);
        self::assertStringContainsString("'charge_null_'", $output);
        self::assertStringContainsString("'refund_null_'", $output);
    }

    #[Test]
    public function gatewayContainsIdempotencyLogic(): void
    {
        $output = $this->templates->gateway('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeGateway', $output);
        self::assertStringContainsString('ParametersHasher::hash(', $output);
        self::assertStringContainsString('$this->idempotencyStore->claim(', $output);
        self::assertStringContainsString('IdempotencyClaimStatus::Replay', $output);
        self::assertStringContainsString('IdempotencyClaimStatus::Mismatch', $output);
        self::assertStringContainsString('StripeException::idempotencyMismatch(', $output);
        self::assertStringContainsString('$this->auditLogger->log(', $output);
        self::assertStringContainsString('$this->idempotencyStore->release(', $output);
    }

    #[Test]
    public function parametersHasherUsesSha256(): void
    {
        $output = $this->templates->parametersHasher('App\\Payment');

        self::assertStringContainsString('namespace App\\Payment\\Gateway;', $output);
        self::assertStringContainsString('final readonly class ParametersHasher', $output);
        self::assertStringContainsString('hash(\'sha256\', $json)', $output);
        self::assertStringContainsString('#[NoDiscard]', $output);
    }

    #[Test]
    public function configContainsDtoWithFromArray(): void
    {
        $output = $this->templates->config('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeConfig', $output);
        self::assertStringContainsString("public string \$provider = 'null'", $output);
        self::assertStringContainsString("public string \$defaultCurrency = 'USD'", $output);
        self::assertStringContainsString('public int $idempotencyTtlSeconds = 86_400', $output);
        self::assertStringContainsString('public static function fromArray(array $data): self', $output);
    }

    #[Test]
    public function intentContainsToArrayAndFromArray(): void
    {
        $output = $this->templates->intent('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeIntent', $output);
        self::assertStringContainsString('public string $id', $output);
        self::assertStringContainsString('public StripeIntentStatus $status', $output);
        self::assertStringContainsString('public Money $amount', $output);
        self::assertStringContainsString('public function toArray(): array', $output);
        self::assertStringContainsString('public static function fromArray(array $data): self', $output);
    }

    #[Test]
    public function intentStatusContainsCases(): void
    {
        $output = $this->templates->intentStatus('Stripe', 'App\\Payment');

        self::assertStringContainsString('enum StripeIntentStatus: string', $output);
        self::assertStringContainsString("case Pending = 'pending'", $output);
        self::assertStringContainsString("case Captured = 'captured'", $output);
        self::assertStringContainsString("case Cancelled = 'cancelled'", $output);
    }

    #[Test]
    public function chargeContainsStatusField(): void
    {
        $output = $this->templates->charge('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeCharge', $output);
        self::assertStringContainsString('public string $id', $output);
        self::assertStringContainsString('public string $intentId', $output);
        self::assertStringContainsString('public StripeChargeStatus $status', $output);
    }

    #[Test]
    public function chargeStatusContainsCases(): void
    {
        $output = $this->templates->chargeStatus('Stripe', 'App\\Payment');

        self::assertStringContainsString('enum StripeChargeStatus: string', $output);
        self::assertStringContainsString("case Succeeded = 'succeeded'", $output);
        self::assertStringContainsString("case Failed = 'failed'", $output);
    }

    #[Test]
    public function refundContainsAmountField(): void
    {
        $output = $this->templates->refund('Stripe', 'App\\Payment');

        self::assertStringContainsString('final readonly class StripeRefund', $output);
        self::assertStringContainsString('public string $id', $output);
        self::assertStringContainsString('public string $chargeId', $output);
        self::assertStringContainsString('public StripeRefundStatus $status', $output);
        self::assertStringContainsString('public Money $amount', $output);
    }

    #[Test]
    public function refundStatusContainsCases(): void
    {
        $output = $this->templates->refundStatus('Stripe', 'App\\Payment');

        self::assertStringContainsString('enum StripeRefundStatus: string', $output);
        self::assertStringContainsString("case Succeeded = 'succeeded'", $output);
        self::assertStringContainsString("case Failed = 'failed'", $output);
        self::assertStringContainsString("case Pending = 'pending'", $output);
    }

    #[Test]
    public function moneyIsValueObject(): void
    {
        $output = $this->templates->money('App\\Payment');

        self::assertStringContainsString('namespace App\\Payment\\Domain;', $output);
        self::assertStringContainsString('final readonly class Money', $output);
        self::assertStringContainsString('public int $amount', $output);
        self::assertStringContainsString('monetary amount in minor units (cents)', $output);
    }

    #[Test]
    public function exceptionContainsStaticFactories(): void
    {
        $output = $this->templates->exception('Stripe', 'App\\Payment');

        self::assertStringContainsString('final class StripeException extends RuntimeException', $output);
        self::assertStringContainsString('public static function providerFailed(string $reason): self', $output);
        self::assertStringContainsString('public static function idempotencyMismatch(string $key): self', $output);
        self::assertStringContainsString('Stripe provider failed:', $output);
        self::assertStringContainsString('previously used with different parameters', $output);
    }

    #[Test]
    public function providerExceptionContainsStaticFactories(): void
    {
        $output = $this->templates->providerException('Stripe', 'App\\Payment');

        self::assertStringContainsString('final class StripeProviderException extends RuntimeException', $output);
        self::assertStringContainsString('public static function unavailable(string $reason): self', $output);
        self::assertStringContainsString('public static function rejected(string $reason): self', $output);
        self::assertStringContainsString('Stripe provider unavailable:', $output);
        self::assertStringContainsString('Stripe provider rejected request:', $output);
    }

    #[Test]
    public function serviceProviderBindsInterface(): void
    {
        $output = $this->templates->serviceProvider('Stripe', 'App\\Payment');

        self::assertStringContainsString('namespace App\\Payment;', $output);
        self::assertStringContainsString('class StripeServiceProvider implements ServiceProviderInterface', $output);
        self::assertStringContainsString('$container->bind(StripeProviderInterface::class, NullStripeProvider::class)', $output);
    }

    #[Test]
    public function readmeContainsModuleStructure(): void
    {
        $output = $this->templates->readme('Stripe');

        self::assertStringContainsString('# Stripe Payment Flow', $output);
        self::assertStringContainsString('Contracts/', $output);
        self::assertStringContainsString('Gateway/', $output);
        self::assertStringContainsString('Domain/', $output);
        self::assertStringContainsString('Exception/', $output);
    }

    #[Test]
    public function gatewayTestContainsIdempotencyTest(): void
    {
        $output = $this->templates->gatewayTest('Stripe', 'Payment', 'App\\Payment');

        self::assertStringContainsString('namespace Pulsar\\Tests\\Unit\\Modules\\Payment\\Gateway;', $output);
        self::assertStringContainsString('#[CoversClass(StripeGateway::class)]', $output);
        self::assertStringContainsString('it_creates_intent_with_idempotency', $output);
        self::assertStringContainsString('IdempotencyClaim::claimed()', $output);
    }
}
