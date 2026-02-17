<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * CSRF configuration for form-specific token binding.
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
        $enabled = is_bool($data['enabled'] ?? null) ? $data['enabled'] : true;
        $rawTtl = $data['ttl'] ?? 3600;
        $ttl = is_int($rawTtl) ? $rawTtl : 3600;
        $fieldName = is_string($data['field_name'] ?? null) ? $data['field_name'] : '_csrf_token';

        return new self(
            enabled: $enabled,
            ttl: $ttl,
            fieldName: $fieldName,
        );
    }
}
