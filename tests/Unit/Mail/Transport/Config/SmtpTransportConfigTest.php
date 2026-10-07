<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Transport\Config\SmtpTransportConfig;

#[CoversClass(SmtpTransportConfig::class)]
final class SmtpTransportConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new SmtpTransportConfig();

        self::assertSame('localhost', $config->host);
        self::assertSame(587, $config->port);
        self::assertNull($config->username);
        self::assertNull($config->password);
        self::assertSame('tls', $config->encryption);
        self::assertSame(30, $config->timeout);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = SmtpTransportConfig::fromArray([
            'host' => 'smtp.hospital-mail.org',
            'port' => 465,
            'username' => 'noreply@hospital.org',
            'password' => 's3cure-p4ssw0rd!',
            'encryption' => 'ssl',
            'timeout' => 60,
        ]);

        self::assertSame('smtp.hospital-mail.org', $config->host);
        self::assertSame(465, $config->port);
        self::assertSame('noreply@hospital.org', $config->username);
        self::assertSame('s3cure-p4ssw0rd!', $config->password);
        self::assertSame('ssl', $config->encryption);
        self::assertSame(60, $config->timeout);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = SmtpTransportConfig::fromArray([
            'host' => 123,
            'port' => 'not-a-number',
            'username' => false,
            'password' => [],
            'encryption' => null,
            'timeout' => 'slow',
        ]);

        self::assertSame('localhost', $config->host);
        self::assertSame(587, $config->port);
        self::assertNull($config->username);
        self::assertNull($config->password);
        self::assertSame('tls', $config->encryption);
        self::assertSame(30, $config->timeout);
    }

    #[Test]
    public function fromArrayWithEmptyArray(): void
    {
        $config = SmtpTransportConfig::fromArray([]);

        self::assertSame('localhost', $config->host);
        self::assertSame(587, $config->port);
    }
}
