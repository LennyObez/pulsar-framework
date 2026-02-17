<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\Config\SqsDriverConfig;

#[CoversClass(SqsDriverConfig::class)]
final class SqsDriverConfigTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $config = new SqsDriverConfig();

        self::assertSame('us-east-1', $config->region);
        self::assertSame('', $config->key);
        self::assertSame('', $config->secret);
        self::assertSame('', $config->prefix);
    }

    #[Test]
    public function constructsWithCustomValues(): void
    {
        $config = new SqsDriverConfig(
            region: 'eu-west-1',
            key: 'AKIAIOSFODNN7EXAMPLE',
            secret: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            prefix: 'https://sqs.eu-west-1.amazonaws.com/123456789012/',
        );

        self::assertSame('eu-west-1', $config->region);
        self::assertSame('AKIAIOSFODNN7EXAMPLE', $config->key);
        self::assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $config->secret);
        self::assertSame('https://sqs.eu-west-1.amazonaws.com/123456789012/', $config->prefix);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = SqsDriverConfig::fromArray([
            'region' => 'ap-southeast-1',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'prefix' => 'https://sqs.ap-southeast-1.amazonaws.com/000/',
        ]);

        self::assertSame('ap-southeast-1', $config->region);
        self::assertSame('test-key', $config->key);
        self::assertSame('test-secret', $config->secret);
        self::assertSame('https://sqs.ap-southeast-1.amazonaws.com/000/', $config->prefix);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = SqsDriverConfig::fromArray([]);

        self::assertSame('us-east-1', $config->region);
        self::assertSame('', $config->key);
        self::assertSame('', $config->secret);
        self::assertSame('', $config->prefix);
    }

    #[Test]
    public function fromArrayWithInvalidTypeFallsBackToDefaults(): void
    {
        $config = SqsDriverConfig::fromArray([
            'region' => 42,
            'key' => [],
            'secret' => true,
            'prefix' => 0,
        ]);

        self::assertSame('us-east-1', $config->region);
        self::assertSame('', $config->key);
        self::assertSame('', $config->secret);
        self::assertSame('', $config->prefix);
    }
}
