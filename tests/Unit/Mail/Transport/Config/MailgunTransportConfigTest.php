<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Transport\Config\MailgunTransportConfig;

#[CoversClass(MailgunTransportConfig::class)]
final class MailgunTransportConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new MailgunTransportConfig();

        self::assertSame('', $config->domain);
        self::assertSame('', $config->apiKey);
        self::assertSame('https://api.mailgun.net', $config->endpoint);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = MailgunTransportConfig::fromArray([
            'domain' => 'mg.hospital.org',
            'api_key' => 'key-not-a-real-credential-test-fixture-only',
            'endpoint' => 'https://api.eu.mailgun.net',
        ]);

        self::assertSame('mg.hospital.org', $config->domain);
        self::assertSame('key-not-a-real-credential-test-fixture-only', $config->apiKey);
        self::assertSame('https://api.eu.mailgun.net', $config->endpoint);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = MailgunTransportConfig::fromArray([
            'domain' => 42,
            'api_key' => false,
            'endpoint' => [],
        ]);

        self::assertSame('', $config->domain);
        self::assertSame('', $config->apiKey);
        self::assertSame('https://api.mailgun.net', $config->endpoint);
    }
}
