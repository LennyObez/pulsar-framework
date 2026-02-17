<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\CloudConfig;

#[CoversClass(CloudConfig::class)]
final class CloudConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new CloudConfig();

        self::assertSame('aws', $config->defaultProvider);
        self::assertSame([], $config->aws);
        self::assertSame([], $config->gcp);
        self::assertSame([], $config->azure);
        self::assertSame(30, $config->httpTimeout);
        self::assertSame(3, $config->retryAttempts);
        self::assertSame(0.5, $config->retryDelay);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = CloudConfig::fromArray([
            'default_provider' => 'gcp',
            'aws' => ['region' => 'eu-west-1'],
            'gcp' => ['project_id' => 'my-project'],
            'azure' => ['tenant_id' => 'abc123'],
            'http_timeout' => 60,
            'retry_attempts' => 5,
            'retry_delay' => 1.0,
        ]);

        self::assertSame('gcp', $config->defaultProvider);
        self::assertSame(['region' => 'eu-west-1'], $config->aws);
        self::assertSame(['project_id' => 'my-project'], $config->gcp);
        self::assertSame(['tenant_id' => 'abc123'], $config->azure);
        self::assertSame(60, $config->httpTimeout);
        self::assertSame(5, $config->retryAttempts);
        self::assertSame(1.0, $config->retryDelay);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = CloudConfig::fromArray([]);

        self::assertSame('aws', $config->defaultProvider);
        self::assertSame([], $config->aws);
        self::assertSame(30, $config->httpTimeout);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = CloudConfig::fromArray([
            'default_provider' => 42,
            'aws' => 'not-an-array',
            'http_timeout' => 'not-an-int',
            'retry_attempts' => false,
            'retry_delay' => 'not-a-float',
        ]);

        self::assertSame('aws', $config->defaultProvider);
        self::assertSame([], $config->aws);
        self::assertSame(30, $config->httpTimeout);
        self::assertSame(3, $config->retryAttempts);
        self::assertSame(0.5, $config->retryDelay);
    }
}
