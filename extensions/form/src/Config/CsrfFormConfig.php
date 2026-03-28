<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     enabled?: bool,
     *     ttl?: int,
     *     field_name?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            ttl: $data['ttl'] ?? 3600,
            fieldName: $data['field_name'] ?? '_csrf_token',
        );
    }
}
