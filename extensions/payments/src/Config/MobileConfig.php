<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Mobile in-app purchase configuration (App Store + Google Play).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MobileConfig
{
    /**
     * @param array{bundle_id: string, issuer_id: string, key_id: string, private_key_path: string, environment?: string} $apple
     * @param array{package_name: string, service_account_json: string, api_base_url?: string} $google
     */
    public function __construct(
        public bool $enabled,
        public array $apple,
        public array $google,
        public string $webhookEncryptionKey,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? false);

        /** @var array<string, mixed> $appleRaw */
        $appleRaw = is_array($data['apple'] ?? null) ? $data['apple'] : [];

        /** @var array{bundle_id: string, issuer_id: string, key_id: string, private_key_path: string, environment?: string} $apple */
        $apple = [
            'bundle_id' => self::str($appleRaw, 'bundle_id', ''),
            'issuer_id' => self::str($appleRaw, 'issuer_id', ''),
            'key_id' => self::str($appleRaw, 'key_id', ''),
            'private_key_path' => self::str($appleRaw, 'private_key_path', ''),
            'environment' => self::str($appleRaw, 'environment', 'production'),
        ];

        /** @var array<string, mixed> $googleRaw */
        $googleRaw = is_array($data['google'] ?? null) ? $data['google'] : [];

        /** @var array{package_name: string, service_account_json: string, api_base_url?: string} $google */
        $google = [
            'package_name' => self::str($googleRaw, 'package_name', ''),
            'service_account_json' => self::str($googleRaw, 'service_account_json', ''),
            'api_base_url' => self::str($googleRaw, 'api_base_url', 'https://androidpublisher.googleapis.com'),
        ];

        $webhookEncryptionKey = self::str($data, 'webhook_encryption_key', '');

        return new self(
            enabled: $enabled,
            apple: $apple,
            google: $google,
            webhookEncryptionKey: $webhookEncryptionKey,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
