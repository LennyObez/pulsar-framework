<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * CSRF configuration for form-specific token binding.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CsrfFormConfig
{
    public function __construct(
        public bool $enabled,
        public int $ttl,
        public string $fieldName,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::bool($data['enabled'] ?? null, true),
            ttl: Coerce::int($data['ttl'] ?? null, 3600),
            fieldName: Coerce::string($data['field_name'] ?? null, '_csrf_token'),
        );
    }
}
