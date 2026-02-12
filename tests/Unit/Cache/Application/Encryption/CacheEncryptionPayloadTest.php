<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Encryption\CacheEncryptionPayload;

#[CoversClass(CacheEncryptionPayload::class)]
final class CacheEncryptionPayloadTest extends TestCase
{
    #[Test]
    public function toJsonAndFromJsonRoundTrip(): void
    {
        $payload = new CacheEncryptionPayload(
            version: 1,
            keyId: 'key-id-123',
            ciphertext: 'encrypted-data',
            aadHash: 'aad-hash-value',
        );

        $json = $payload->toJson();
        $restored = CacheEncryptionPayload::fromJson($json);

        self::assertNotNull($restored);
        self::assertSame(1, $restored->version);
        self::assertSame('key-id-123', $restored->keyId);
        self::assertSame('encrypted-data', $restored->ciphertext);
        self::assertSame('aad-hash-value', $restored->aadHash);
    }

    #[Test]
    public function fromJsonReturnsNullForInvalidJson(): void
    {
        $result = CacheEncryptionPayload::fromJson('not-valid-json{{{');

        self::assertNull($result);
    }

    #[Test]
    public function fromJsonReturnsNullForMissingFields(): void
    {
        $json = json_encode(['v' => 1, 'kid' => 'key'], JSON_THROW_ON_ERROR);

        $result = CacheEncryptionPayload::fromJson($json);

        self::assertNull($result);
    }

    #[Test]
    public function fromJsonReturnsNullWhenVersionMissing(): void
    {
        $json = json_encode(['kid' => 'k', 'ct' => 'c', 'aad' => 'a'], JSON_THROW_ON_ERROR);

        $result = CacheEncryptionPayload::fromJson($json);

        self::assertNull($result);
    }

    #[Test]
    public function toJsonAndFromJsonRoundTripWithTtlSeconds(): void
    {
        $payload = new CacheEncryptionPayload(
            version: 2,
            keyId: 'key-id-456',
            ciphertext: 'encrypted-data',
            aadHash: 'aad-hash-value',
            ttlSeconds: 3600,
        );

        $json = $payload->toJson();
        $restored = CacheEncryptionPayload::fromJson($json);

        self::assertNotNull($restored);
        self::assertSame(3600, $restored->ttlSeconds);
    }

    #[Test]
    public function ttlSecondsDefaultsToNull(): void
    {
        $payload = new CacheEncryptionPayload(
            version: 1,
            keyId: 'key-id-123',
            ciphertext: 'encrypted-data',
            aadHash: 'aad-hash-value',
        );

        self::assertNull($payload->ttlSeconds);

        $json = $payload->toJson();
        $restored = CacheEncryptionPayload::fromJson($json);

        self::assertNotNull($restored);
        self::assertNull($restored->ttlSeconds);
    }
}
