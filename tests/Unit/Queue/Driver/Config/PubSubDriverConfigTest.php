<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\Config\PubSubDriverConfig;

#[CoversClass(PubSubDriverConfig::class)]
final class PubSubDriverConfigTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $config = new PubSubDriverConfig();

        self::assertSame('', $config->projectId);
        self::assertSame('', $config->keyFilePath);
        self::assertSame('pulsar-queue-', $config->topicPrefix);
        self::assertSame('pulsar-worker-', $config->subscriptionPrefix);
    }

    #[Test]
    public function constructsWithCustomValues(): void
    {
        $config = new PubSubDriverConfig(
            projectId: 'my-gcp-project',
            keyFilePath: '/etc/gcp/credentials.json',
            topicPrefix: 'prod-jobs-',
            subscriptionPrefix: 'prod-worker-',
        );

        self::assertSame('my-gcp-project', $config->projectId);
        self::assertSame('/etc/gcp/credentials.json', $config->keyFilePath);
        self::assertSame('prod-jobs-', $config->topicPrefix);
        self::assertSame('prod-worker-', $config->subscriptionPrefix);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = PubSubDriverConfig::fromArray([
            'project_id' => 'test-project',
            'key_file_path' => '/path/to/key.json',
            'topic_prefix' => 'staging-',
            'subscription_prefix' => 'staging-sub-',
        ]);

        self::assertSame('test-project', $config->projectId);
        self::assertSame('/path/to/key.json', $config->keyFilePath);
        self::assertSame('staging-', $config->topicPrefix);
        self::assertSame('staging-sub-', $config->subscriptionPrefix);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = PubSubDriverConfig::fromArray([]);

        self::assertSame('', $config->projectId);
        self::assertSame('', $config->keyFilePath);
        self::assertSame('pulsar-queue-', $config->topicPrefix);
        self::assertSame('pulsar-worker-', $config->subscriptionPrefix);
    }

    #[Test]
    public function fromArrayWithInvalidTypeFallsBackToDefaults(): void
    {
        $config = PubSubDriverConfig::fromArray([
            'project_id' => 123,
            'key_file_path' => true,
            'topic_prefix' => [],
            'subscription_prefix' => null,
        ]);

        self::assertSame('', $config->projectId);
        self::assertSame('', $config->keyFilePath);
        self::assertSame('pulsar-queue-', $config->topicPrefix);
        self::assertSame('pulsar-worker-', $config->subscriptionPrefix);
    }

    #[Test]
    public function fromArrayWithPartialData(): void
    {
        $config = PubSubDriverConfig::fromArray([
            'project_id' => 'partial-project',
        ]);

        self::assertSame('partial-project', $config->projectId);
        self::assertSame('', $config->keyFilePath);
        self::assertSame('pulsar-queue-', $config->topicPrefix);
    }
}
