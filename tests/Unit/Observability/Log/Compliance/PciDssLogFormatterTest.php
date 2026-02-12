<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Compliance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\Compliance\PciDssLogFormatter;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(PciDssLogFormatter::class)]
final class PciDssLogFormatterTest extends TestCase
{
    private PciDssLogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new PciDssLogFormatter();
    }

    #[Test]
    public function masksVisaCardNumber(): void
    {
        $entry = $this->createEntry(['card_number' => '4111111111111111']);

        $result = $this->formatter->format($entry);

        self::assertSame('************1111', $result->context['card_number']);
    }

    #[Test]
    public function masksMastercardNumber(): void
    {
        $entry = $this->createEntry(['card_number' => '5500000000000004']);

        $result = $this->formatter->format($entry);

        self::assertSame('************0004', $result->context['card_number']);
    }

    #[Test]
    public function masksAmexCardNumber(): void
    {
        // Amex: 15 digits
        $entry = $this->createEntry(['card_number' => '378282246310005']);

        $result = $this->formatter->format($entry);

        self::assertSame('***********0005', $result->context['card_number']);
    }

    #[Test]
    public function masksCardNumberWithSpaces(): void
    {
        $entry = $this->createEntry(['card_number' => '4111 1111 1111 1111']);

        $result = $this->formatter->format($entry);

        $cardNumber = $result->context['card_number'];
        self::assertIsString($cardNumber);
        self::assertStringEndsWith('1111', $cardNumber);
        self::assertStringNotContainsString('4111', $cardNumber);
    }

    #[Test]
    public function masksCardNumberWithDashes(): void
    {
        $entry = $this->createEntry(['card_number' => '4111-1111-1111-1111']);

        $result = $this->formatter->format($entry);

        $cardNumber = $result->context['card_number'];
        self::assertIsString($cardNumber);
        self::assertStringEndsWith('1111', $cardNumber);
        self::assertStringNotContainsString('4111', $cardNumber);
    }

    #[Test]
    public function fullyMasksCvv(): void
    {
        $entry = $this->createEntry(['cvv' => '123']);

        $result = $this->formatter->format($entry);

        self::assertSame('***', $result->context['cvv']);
    }

    #[Test]
    public function fullyMasksCvc2(): void
    {
        $entry = $this->createEntry(['cvc2' => '456']);

        $result = $this->formatter->format($entry);

        self::assertSame('***', $result->context['cvc2']);
    }

    #[Test]
    public function fullyMasksSecurityCode(): void
    {
        $entry = $this->createEntry(['security_code' => '789']);

        $result = $this->formatter->format($entry);

        self::assertSame('***', $result->context['security_code']);
    }

    #[Test]
    public function masksExpiryDate(): void
    {
        $entry = $this->createEntry(['expiry' => '12/25']);

        $result = $this->formatter->format($entry);

        self::assertSame('**/**', $result->context['expiry']);
    }

    #[Test]
    public function masksExpMonth(): void
    {
        $entry = $this->createEntry(['exp_month' => '12']);

        $result = $this->formatter->format($entry);

        self::assertSame('**/**', $result->context['exp_month']);
    }

    #[Test]
    public function masksCardNumberInMessage(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'Payment with card 4111111111111111 processed',
            context: [],
            channel: 'payment',
            timestamp: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );

        $result = $this->formatter->format($entry);

        self::assertStringContainsString('************1111', $result->message);
        self::assertStringNotContainsString('4111111111111111', $result->message);
    }

    #[Test]
    public function doesNotMaskNonLuhnValidNumbers(): void
    {
        // 1234567890123 is 13 digits but does NOT pass Luhn
        $entry = $this->createEntry(['reference' => '1234567890123']);

        $result = $this->formatter->format($entry);

        self::assertSame('1234567890123', $result->context['reference']);
    }

    #[Test]
    public function preservesNonSensitiveContext(): void
    {
        $entry = $this->createEntry([
            'action' => 'payment',
            'amount' => 99.99,
            'currency' => 'USD',
        ]);

        $result = $this->formatter->format($entry);

        self::assertSame('payment', $result->context['action']);
        self::assertSame(99.99, $result->context['amount']);
        self::assertSame('USD', $result->context['currency']);
    }

    #[Test]
    public function masksCardNumberInNestedContext(): void
    {
        $entry = $this->createEntry([
            'payment' => [
                'card_number' => '4111111111111111',
                'cvv' => '123',
            ],
        ]);

        $result = $this->formatter->format($entry);

        $payment = $result->context['payment'];
        self::assertIsArray($payment);
        self::assertSame('************1111', $payment['card_number']);
        self::assertSame('***', $payment['cvv']);
    }

    #[Test]
    public function returnsNewLogEntryInstance(): void
    {
        $entry = $this->createEntry(['cvv' => '123']);

        $result = $this->formatter->format($entry);

        self::assertNotSame($entry, $result);
        self::assertSame($entry->level, $result->level);
        self::assertSame($entry->channel, $result->channel);
        self::assertSame($entry->timestamp, $result->timestamp);
    }

    #[Test]
    public function caseInsensitiveCvvKeyMatching(): void
    {
        $entry = $this->createEntry(['CVV' => '999']);

        $result = $this->formatter->format($entry);

        self::assertSame('***', $result->context['CVV']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createEntry(array $context = []): LogEntry
    {
        return new LogEntry(
            level: LogLevel::Info,
            message: 'test',
            context: $context,
            channel: 'payment',
            timestamp: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );
    }
}
