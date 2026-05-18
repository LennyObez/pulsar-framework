<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     enabled?: bool|int|string,
     *     apple?: array{bundle_id?: string, issuer_id?: string, key_id?: string, private_key_path?: string, environment?: string},
     *     google?: array{package_name?: string, service_account_json?: string, api_base_url?: string},
     *     webhook_encryption_key?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $appleRaw = $data['apple'] ?? [];
        $apple = [
            'bundle_id' => $appleRaw['bundle_id'] ?? '',
            'issuer_id' => $appleRaw['issuer_id'] ?? '',
            'key_id' => $appleRaw['key_id'] ?? '',
            'private_key_path' => $appleRaw['private_key_path'] ?? '',
            'environment' => $appleRaw['environment'] ?? 'production',
        ];

        $googleRaw = $data['google'] ?? [];
        $google = [
            'package_name' => $googleRaw['package_name'] ?? '',
            'service_account_json' => $googleRaw['service_account_json'] ?? '',
            'api_base_url' => $googleRaw['api_base_url'] ?? 'https://androidpublisher.googleapis.com',
        ];

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            apple: $apple,
            google: $google,
            webhookEncryptionKey: $data['webhook_encryption_key'] ?? '',
        );
    }
}
