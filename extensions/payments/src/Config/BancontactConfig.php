<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Bancontact payment gateway configuration.
 *
 * Bancontact is Belgium's most popular electronic payment system.
 * Integrated via Stripe Payment Methods API.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BancontactConfig
{
    public function __construct(
        public bool $enabled,
        public string $preferredLanguage,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? false);
        $langVal = $data['preferred_language'] ?? null;
        $preferredLanguage = is_string($langVal) ? $langVal : 'nl';

        return new self(
            enabled: $enabled,
            preferredLanguage: $preferredLanguage,
        );
    }
}
