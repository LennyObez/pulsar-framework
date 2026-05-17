<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\ConsentBanner;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_string;

/**
 * Represents a consent category in the cookie banner.
 *
 * Each category groups related cookies/tracking purposes together.
 * Categories marked as "required" cannot be opted out of (e.g. session cookies).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConsentCategory
{
    /**
     * @param string $key Machine identifier (e.g. 'analytics', 'marketing')
     * @param string $label Human-readable label
     * @param string $description Explanation of what cookies in this category do
     * @param bool $required Whether this category cannot be opted out of
     * @param bool $defaultEnabled Whether this category is on by default
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $required = false,
        public bool $defaultEnabled = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key: $key,
            label: isset($data['label']) && is_string($data['label']) ? $data['label'] : $key,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : '',
            required: isset($data['required']) && is_bool($data['required']) ? $data['required'] : false,
            defaultEnabled: isset($data['default_enabled']) && is_bool($data['default_enabled']) ? $data['default_enabled'] : false,
        );
    }
}
