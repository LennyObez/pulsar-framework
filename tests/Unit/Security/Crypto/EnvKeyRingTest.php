<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use function iterator_to_array;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\Crypto\MasterKey;

#[CoversClass(EnvKeyRing::class)]
final class EnvKeyRingTest extends TestCase
{
    #[Test]
    public function keyForReturnsKeyByKid(): void
    {
        $ring = new EnvKeyRing(['kid1' => 'key1', 'kid2' => 'key2']);

        self::assertSame('key1', $ring->keyFor('kid1'));
        self::assertSame('key2', $ring->keyFor('kid2'));
    }

    #[Test]
    public function keyForReturnsNullForUnknownKid(): void
    {
        $ring = new EnvKeyRing(['kid1' => 'key1']);

        self::assertNull($ring->keyFor('unknown'));
    }

    #[Test]
    public function allReturnsAllKeysExcludingAliases(): void
    {
        $ring = new EnvKeyRing(
            ['kid1' => 'key1', 'kid2' => 'key2', 'current' => 'key1'],
            ['current'],
        );

        $all = iterator_to_array($ring->all());

        self::assertCount(2, $all);
        self::assertSame('key1', $all['kid1']);
        self::assertSame('key2', $all['kid2']);
        self::assertArrayNotHasKey('current', $all);
    }

    #[Test]
    public function fromMasterKeyRegistersCurrentKey(): void
    {
        $hex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($hex);

        $ring = EnvKeyRing::fromMasterKey($masterKey, 2, 'audit___');

        $kid = $masterKey->keyId(2, 'audit___');
        self::assertNotNull($ring->keyFor($kid));

        $all = iterator_to_array($ring->all());
        self::assertCount(1, $all);
    }

    #[Test]
    public function fromMasterKeyRegistersCurrentAndPreviousKeys(): void
    {
        $hex = sodium_bin2hex(random_bytes(32));
        $previousHex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($hex, $previousHex);

        $ring = EnvKeyRing::fromMasterKey($masterKey, 2, 'audit___');

        $currentKid = $masterKey->keyId(2, 'audit___');
        $previousKid = $masterKey->previousKeyId(2, 'audit___');

        self::assertNotNull($ring->keyFor($currentKid));
        self::assertNotNull($previousKid);
        self::assertNotNull($ring->keyFor($previousKid));

        $all = iterator_to_array($ring->all());
        self::assertCount(2, $all);
    }
}
