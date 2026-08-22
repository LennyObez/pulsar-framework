<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\MobileConfig;

final class MobileConfigTest extends TestCase
{
    #[Test]
    public function fromArrayParsesFullConfig(): void
    {
        $config = MobileConfig::fromArray([
            'enabled' => true,
            'apple' => [
                'bundle_id' => 'com.example.app',
                'issuer_id' => 'iss-1',
                'key_id' => 'key-1',
                'private_key_path' => '/keys/apple.p8',
                'environment' => 'sandbox',
            ],
            'google' => [
                'package_name' => 'com.example.app',
                'service_account_json' => '/keys/google.json',
                'api_base_url' => 'https://custom.googleapis.com',
            ],
            'webhook_encryption_key' => 'enc-key-123',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('com.example.app', $config->apple['bundle_id']);
        self::assertSame('sandbox', $config->apple['environment'] ?? null);
        self::assertSame('com.example.app', $config->google['package_name']);
        self::assertSame('https://custom.googleapis.com', $config->google['api_base_url'] ?? null);
        self::assertSame('enc-key-123', $config->webhookEncryptionKey);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = MobileConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('', $config->apple['bundle_id']);
        self::assertSame('production', $config->apple['environment'] ?? null);
        self::assertSame('', $config->google['package_name']);
        self::assertSame('https://androidpublisher.googleapis.com', $config->google['api_base_url'] ?? null);
        self::assertSame('', $config->webhookEncryptionKey);
    }
}
