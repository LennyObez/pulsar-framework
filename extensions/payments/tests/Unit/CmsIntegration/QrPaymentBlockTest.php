<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\CmsIntegration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\CmsIntegration\QrPaymentBlock;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Support\QrCodeEncoder;

final class QrPaymentBlockTest extends TestCase
{
    private QrPaymentBlock $block;

    protected function setUp(): void
    {
        $this->block = new QrPaymentBlock(
            new QrCodeEncoder(),
            PayconiqConfig::fromArray(['merchant_id' => 'merch_test']),
        );
    }

    #[Test]
    public function typeReturnsQrPayment(): void
    {
        self::assertSame('qr-payment', $this->block->type());
    }

    #[Test]
    public function schemaContainsRequiredFields(): void
    {
        $schema = $this->block->schema();

        self::assertSame('object', $schema['type']);
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('format', $properties);
        self::assertArrayHasKey('amount', $properties);
        self::assertArrayHasKey('currency', $properties);
        /** @var list<string> $required */
        $required = $schema['required'];
        self::assertContains('format', $required);
        self::assertContains('amount', $required);
        self::assertContains('currency', $required);
    }

    #[Test]
    public function renderProducesValidHtmlWithEpcQr(): void
    {
        $html = $this->block->render([
            'format' => 'epc_qr',
            'amount' => 2500,
            'currency' => 'EUR',
            'description' => 'Invoice payment',
            'beneficiary_name' => 'Test Co',
            'iban' => 'BE68539007547034',
        ]);

        self::assertStringContainsString('qr-payment-block', $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('Invoice payment', $html);
        self::assertStringContainsString('25.00', $html);
    }

    #[Test]
    public function renderEscapesHtmlInDescription(): void
    {
        $html = $this->block->render([
            'format' => 'payment_link',
            'amount' => 100,
            'currency' => 'EUR',
            'description' => '<script>alert("xss")</script>',
            'payment_url' => 'https://example.com/pay',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderWithPayconiqFormat(): void
    {
        $html = $this->block->render([
            'format' => 'payconiq',
            'amount' => 1500,
            'currency' => 'EUR',
            'reference' => 'PAY-001',
        ]);

        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('qr-payment-block--center', $html);
    }

    #[Test]
    public function renderWithPaymentLinkFormat(): void
    {
        $html = $this->block->render([
            'format' => 'payment_link',
            'amount' => 9900,
            'currency' => 'EUR',
            'payment_url' => 'https://pay.example.com/checkout/abc',
        ]);

        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('99.00', $html);
    }

    #[Test]
    public function renderRespectsAlignment(): void
    {
        $html = $this->block->render([
            'format' => 'payment_link',
            'amount' => 100,
            'currency' => 'EUR',
            'alignment' => 'right',
            'payment_url' => 'https://example.com',
        ]);

        self::assertStringContainsString('qr-payment-block--right', $html);
    }

    #[Test]
    public function validateReturnsNoErrorsForValidEpcData(): void
    {
        $errors = $this->block->validate([
            'format' => 'epc_qr',
            'amount' => 2500,
            'currency' => 'EUR',
            'beneficiary_name' => 'Test Co',
            'iban' => 'BE68539007547034',
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateRequiresFormatAmountCurrency(): void
    {
        $errors = $this->block->validate([]);

        self::assertCount(3, $errors);
        self::assertStringContainsString('format', $errors[0]);
        self::assertStringContainsString('amount', $errors[1]);
        self::assertStringContainsString('currency', $errors[2]);
    }

    #[Test]
    public function validateRejectsInvalidFormat(): void
    {
        $errors = $this->block->validate([
            'format' => 'bitcoin',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('epc_qr, payconiq, payment_link', $errors[0]);
    }

    #[Test]
    public function validateRejectsNegativeAmount(): void
    {
        $errors = $this->block->validate([
            'format' => 'epc_qr',
            'amount' => -100,
            'currency' => 'EUR',
            'beneficiary_name' => 'Test',
            'iban' => 'BE123',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('positive integer', $errors[0]);
    }

    #[Test]
    public function validateRequiresBeneficiaryAndIbanForEpcQr(): void
    {
        $errors = $this->block->validate([
            'format' => 'epc_qr',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        self::assertCount(2, $errors);
        self::assertStringContainsString('beneficiary_name', $errors[0]);
        self::assertStringContainsString('iban', $errors[1]);
    }

    #[Test]
    public function validateRequiresPaymentUrlForPaymentLink(): void
    {
        $errors = $this->block->validate([
            'format' => 'payment_link',
            'amount' => 100,
            'currency' => 'EUR',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('payment_url', $errors[0]);
    }

    #[Test]
    public function validateRejectsInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'format' => 'payment_link',
            'amount' => 100,
            'currency' => 'EUR',
            'payment_url' => 'https://example.com',
            'alignment' => 'invalid',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('left, center, right', $errors[0]);
    }

    #[Test]
    #[DataProvider('validAlignmentProvider')]
    public function validateAcceptsValidAlignments(string $alignment): void
    {
        $errors = $this->block->validate([
            'format' => 'payment_link',
            'amount' => 100,
            'currency' => 'EUR',
            'payment_url' => 'https://example.com',
            'alignment' => $alignment,
        ]);

        self::assertSame([], $errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validAlignmentProvider(): iterable
    {
        yield 'left' => ['left'];
        yield 'center' => ['center'];
        yield 'right' => ['right'];
    }
}
