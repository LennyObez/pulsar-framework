<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Transport\Config\SesTransportConfig;

#[CoversClass(SesTransportConfig::class)]
final class SesTransportConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new SesTransportConfig();

        self::assertSame('us-east-1', $config->region);
        self::assertSame('', $config->accessKey);
        self::assertSame('', $config->secretKey);
        self::assertNull($config->endpoint);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = SesTransportConfig::fromArray([
            'region' => 'eu-west-1',
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'endpoint' => 'https://email.eu-west-1.amazonaws.com',
        ]);

        self::assertSame('eu-west-1', $config->region);
        self::assertSame('AKIAIOSFODNN7EXAMPLE', $config->accessKey);
        self::assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $config->secretKey);
        self::assertSame('https://email.eu-west-1.amazonaws.com', $config->endpoint);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = SesTransportConfig::fromArray([
            'region' => 123,
            'access_key' => false,
            'secret_key' => [],
            'endpoint' => true,
        ]);

        self::assertSame('us-east-1', $config->region);
        self::assertSame('', $config->accessKey);
        self::assertSame('', $config->secretKey);
        self::assertNull($config->endpoint);
    }
}
