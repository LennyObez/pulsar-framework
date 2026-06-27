<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\SignedIdempotencyEnvelope;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SubKeyId;

use function is_string;
use function json_decode;
use function random_bytes;
use function sodium_bin2hex;

/**
 * ADR-0006 registry guard: the subkey id the {@see SignedIdempotencyEnvelope}
 * actually signs under must equal the value pinned in the framework-wide
 * {@see SubKeyId} registry. Before the fix the envelope shipped id 12 while
 * the registry declared 11, so a caller deriving the registry value produced
 * a key that could not verify any envelope the framework had ever sealed.
 */
#[CoversClass(SignedIdempotencyEnvelope::class)]
#[CoversClass(SubKeyId::class)]
final class IdempotencyEnvelopeSubKeyRegistryTest extends TestCase
{
    /**
     * The shipped envelope subkey id is 12 (live code predates the
     * registry). Pin the registry to it so a future reorder cannot
     * silently re-key every sealed idempotency payload.
     */
    #[Test]
    public function registryPinsEnvelopeSubKeyToShippedValue(): void
    {
        self::assertSame(12, SubKeyId::IdempotencyEnvelope->value);
    }

    /**
     * Behavioural anchor: the kid embedded by seal() must match the kid a
     * caller computes from the registry value and the documented context.
     * If the envelope constant and the registry case ever diverge again,
     * the two kids differ and this assertion fails.
     */
    #[Test]
    public function sealedEnvelopeKidMatchesRegistryDerivedKid(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $envelope = new SignedIdempotencyEnvelope($masterKey);

        $sealed = $envelope->seal('idem-registry', 'payload');

        $decoded = json_decode($sealed, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('kid', $decoded);
        self::assertTrue(is_string($decoded['kid']));

        $expectedKid = $masterKey->keyId(SubKeyId::IdempotencyEnvelope->value, 'idemcach');

        self::assertSame($expectedKid, $decoded['kid']);
    }
}
