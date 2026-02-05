<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Encryption;

use JsonException;
use Pulsar\Api\Internal;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Ciphertext + key ID DTO for encrypted cache values.
 */
#[Internal]
final readonly class CacheEncryptionPayload
{
    public function __construct(
        public int $version,
        public string $keyId,
        public string $ciphertext,
        public string $aadHash,
        public ?int $ttlSeconds = null,
    ) {}

    public function toJson(): string
    {
        $data = [
            'v' => $this->version,
            'kid' => $this->keyId,
            'ct' => $this->ciphertext,
            'aad' => $this->aadHash,
        ];

        if ($this->ttlSeconds !== null) {
            $data['ttl'] = $this->ttlSeconds;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): ?self
    {
        try {
            /** @var array{v?: int, kid?: string, ct?: string, aad?: string, ttl?: int|null} $data */
            $data = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!isset($data['v'], $data['kid'], $data['ct'], $data['aad'])) {
            return null;
        }

        return new self(
            version: $data['v'],
            keyId: $data['kid'],
            ciphertext: $data['ct'],
            aadHash: $data['aad'],
            ttlSeconds: $data['ttl'] ?? null,
        );
    }
}
