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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string $secretKey */
        $secretKey = $data['secret_key'] ?? '';
        /** @var string $publishableKey */
        $publishableKey = $data['publishable_key'] ?? '';
        /** @var string $webhookSecret */
        $webhookSecret = $data['webhook_secret'] ?? '';
        /** @var string $apiVersion */
        $apiVersion = $data['api_version'] ?? '2024-12-18.acacia';
        /** @var bool $testMode */
        $testMode = (bool) ($data['test_mode'] ?? true);

        return new self(
            secretKey: $secretKey,
            publishableKey: $publishableKey,
            webhookSecret: $webhookSecret,
            apiVersion: $apiVersion,
            testMode: $testMode,
        );
    }
}
