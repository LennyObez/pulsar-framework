<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\SubKeyId;

/**
 * Encryption configuration DTO for ORM encrypted columns.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EncryptionConfig
{
    public function __construct(
        public bool $enabled,
        public int $subKeyId,
        public string $context,
        public string $blindIndexContext,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     sub_key_id?: int,
     *     context?: string,
     *     blind_index_context?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? false,
            // Prefer the framework's central SubKeyId enum to make the
            // subsystem assignment visible at review time. The integer fallback
            // is kept for back-compat with configs already shipped.
            subKeyId: $data['sub_key_id'] ?? SubKeyId::Orm->value,
            context: $data['context'] ?? 'orm__enc',
            blindIndexContext: $data['blind_index_context'] ?? 'orm__bidx',
        );
    }
}
