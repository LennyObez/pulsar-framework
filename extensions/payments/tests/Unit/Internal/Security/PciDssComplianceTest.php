<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Internal\Security\PciDssCompliance;

final class PciDssComplianceTest extends TestCase
{
    #[Test]
    public function detects_valid_visa_card_number(): void
    {
        self::assertTrue(PciDssCompliance::detectsPan('4111111111111111'));
    }

    #[Test]
    public function detects_valid_mastercard_number(): void
    {
        self::assertTrue(PciDssCompliance::detectsPan('5500000000000004'));
    }

    #[Test]
    public function detects_card_number_with_spaces(): void
    {
        self::assertTrue(PciDssCompliance::detectsPan('4111 1111 1111 1111'));
    }

    #[Test]
    public function detects_card_number_with_dashes(): void
    {
        self::assertTrue(PciDssCompliance::detectsPan('4111-1111-1111-1111'));
    }

    #[Test]
    public function rejects_too_short_number(): void
    {
        self::assertFalse(PciDssCompliance::detectsPan('411111'));
    }

    #[Test]
    public function rejects_too_long_number(): void
    {
        self::assertFalse(PciDssCompliance::detectsPan('41111111111111111111'));
    }

    #[Test]
    public function rejects_non_numeric_string(): void
    {
        self::assertFalse(PciDssCompliance::detectsPan('not-a-card-number'));
    }

    #[Test]
    public function rejects_empty_string(): void
    {
        self::assertFalse(PciDssCompliance::detectsPan(''));
    }

    #[Test]
    public function rejects_number_failing_luhn(): void
    {
        self::assertFalse(PciDssCompliance::detectsPan('4111111111111112'));
    }

    #[Test]
    public function assert_no_pan_passes_for_clean_data(): void
    {
        PciDssCompliance::assertNoPan([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'token' => 'tok_abc123',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assert_no_pan_throws_for_raw_card_in_data(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('PCI-DSS violation');

        PciDssCompliance::assertNoPan([
            'name' => 'Jane Doe',
            'card_number' => '4111111111111111',
        ]);
    }

    #[Test]
    public function assert_no_pan_checks_nested_arrays(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('PCI-DSS violation');

        PciDssCompliance::assertNoPan([
            'billing' => [
                'card' => '5500000000000004',
            ],
        ]);
    }

    /**
     * An unquoted JSON card number decodes to an int, so a string-only check
     * would wave the commonest machine-generated body straight through.
     */
    #[Test]
    public function assert_no_pan_rejects_a_card_number_sent_as_an_integer(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('PCI-DSS violation');

        PciDssCompliance::assertNoPan(['card_number' => 4111111111111111]);
    }

    #[Test]
    public function assert_no_pan_names_the_full_path_of_a_nested_violation(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains("field 'payment.details.number'");

        PciDssCompliance::assertNoPan([
            'payment' => [
                'details' => [
                    'number' => '4111111111111111',
                ],
            ],
        ]);
    }

    #[Test]
    public function assert_no_pan_never_repeats_the_card_number_in_the_message(): void
    {
        try {
            PciDssCompliance::assertNoPan(['card_number' => '4111111111111111']);
        } catch (PaymentException $e) {
            self::assertStringNotContainsString('4111111111111111', $e->getMessage());

            return;
        }

        self::fail('A raw card number was accepted');
    }

    #[Test]
    public function assert_no_pan_scans_list_shaped_values(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains("field 'items.1'");

        PciDssCompliance::assertNoPan([
            'items' => ['sku-1', '4111111111111111'],
        ]);
    }

    #[Test]
    #[DataProvider('maskDataProvider')]
    public function mask_card_number_returns_masked_format(string $last4, string $expected): void
    {
        self::assertSame($expected, PciDssCompliance::maskCardNumber($last4));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function maskDataProvider(): iterable
    {
        yield 'standard 4 digits' => ['1234', '****1234'];
        yield 'longer input shows last 4' => ['51234', '****1234'];
        yield 'short input' => ['99', '****99'];
    }
}
