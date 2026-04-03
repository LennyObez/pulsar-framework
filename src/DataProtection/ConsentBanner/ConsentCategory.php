<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\ConsentBanner;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     label?: string,
     *     description?: string,
     *     required?: bool,
     *     default_enabled?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key: $key,
            label: $data['label'] ?? $key,
            description: $data['description'] ?? '',
            required: $data['required'] ?? false,
            defaultEnabled: $data['default_enabled'] ?? false,
        );
    }
}
