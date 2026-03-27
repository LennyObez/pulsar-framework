<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Stripe gateway configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StripeConfig
{
    public function __construct(
        public string $secretKey,
        public string $publishableKey,
        public string $webhookSecret,
        public string $apiVersion,
        public bool $testMode,
    ) {}

    /**
     * @param array{
     *     secret_key?: string,
     *     publishable_key?: string,
     *     webhook_secret?: string,
     *     api_version?: string,
     *     test_mode?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            secretKey: $data['secret_key'] ?? '',
            publishableKey: $data['publishable_key'] ?? '',
            webhookSecret: $data['webhook_secret'] ?? '',
            apiVersion: $data['api_version'] ?? '2024-12-18.acacia',
            testMode: (bool) ($data['test_mode'] ?? true),
        );
    }
}
