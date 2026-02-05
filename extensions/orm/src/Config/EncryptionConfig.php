<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Encryption configuration DTO for ORM encrypted columns.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var bool $enabled */
        $enabled = $data['enabled'] ?? false;

        /** @var int $subKeyId */
        $subKeyId = $data['sub_key_id'] ?? 5;

        /** @var string $context */
        $context = $data['context'] ?? 'orm__enc';

        /** @var string $blindIndexContext */
        $blindIndexContext = $data['blind_index_context'] ?? 'orm__bidx';

        return new self(
            enabled: $enabled,
            subKeyId: $subKeyId,
            context: $context,
            blindIndexContext: $blindIndexContext,
        );
    }
}
