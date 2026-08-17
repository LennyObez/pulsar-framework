<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\MailDriverType;

#[CoversNothing]
final class MailDriverTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('driverProvider')]
    public function backingValuesAreCorrect(MailDriverType $driver, string $expected): void
    {
        self::assertSame($expected, $driver->value);
    }

    /**
     * @return iterable<string, array{MailDriverType, string}>
     */
    public static function driverProvider(): iterable
    {
        yield 'smtp' => [MailDriverType::Smtp, 'smtp'];
        yield 'ses' => [MailDriverType::Ses, 'ses'];
        yield 'mailgun' => [MailDriverType::Mailgun, 'mailgun'];
        yield 'postmark' => [MailDriverType::Postmark, 'postmark'];
        yield 'sendgrid' => [MailDriverType::Sendgrid, 'sendgrid'];
        yield 'log' => [MailDriverType::Log, 'log'];
        yield 'array' => [MailDriverType::Array, 'array'];
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(7, MailDriverType::cases());
    }
}
