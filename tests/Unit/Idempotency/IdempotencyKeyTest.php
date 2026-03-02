<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\IdempotencyKey;

use function strlen;

#[CoversClass(IdempotencyKey::class)]
final class IdempotencyKeyTest extends TestCase
{
    #[Test]
    public function fromStringCreatesKey(): void
    {
        $key = IdempotencyKey::fromString('my-key');

        self::assertSame('my-key', $key->value);
        self::assertSame('my-key', $key->toString());
        self::assertSame('my-key', (string) $key);
    }

    #[Test]
    public function fromPartsProducesDeterministicHash(): void
    {
        $key1 = IdempotencyKey::fromParts('user-123', 'create_payment', 'amount=100');
        $key2 = IdempotencyKey::fromParts('user-123', 'create_payment', 'amount=100');

        self::assertSame($key1->value, $key2->value);
        self::assertSame(64, strlen($key1->value)); // 32-byte BLAKE2b hex.
    }

    #[Test]
    public function fromPartsDifferentInputsProduceDifferentKeys(): void
    {
        $key1 = IdempotencyKey::fromParts('user-123', 'create_payment');
        $key2 = IdempotencyKey::fromParts('user-456', 'create_payment');

        self::assertNotSame($key1->value, $key2->value);
    }

    #[Test]
    public function fromPartsOrderMatters(): void
    {
        $key1 = IdempotencyKey::fromParts('a', 'b');
        $key2 = IdempotencyKey::fromParts('b', 'a');

        self::assertNotSame($key1->value, $key2->value);
    }

    #[Test]
    public function fromPartsLengthPrefixingPreventsBoundaryCollision(): void
    {
        // Without length prefixing the two would collapse onto the same
        // joined string ('ab|c' / 'a|bc' both parse as 'abc' under naive
        // concatenation); length-prefixing keeps them distinct.
        $key1 = IdempotencyKey::fromParts('ab', 'c');
        $key2 = IdempotencyKey::fromParts('a', 'bc');

        self::assertNotSame($key1->value, $key2->value);
    }

    #[Test]
    public function fromPartsRejectsDelimiterCollisionAttempt(): void
    {
        // A naive `implode('|', $parts)` collapses these inputs to the same
        // string ('a|b|c'). Length prefixing keeps them separate.
        $key1 = IdempotencyKey::fromParts('a|b', 'c');
        $key2 = IdempotencyKey::fromParts('a', 'b', 'c');
        $key3 = IdempotencyKey::fromParts('a', 'b|c');

        self::assertNotSame($key1->value, $key2->value);
        self::assertNotSame($key2->value, $key3->value);
        self::assertNotSame($key1->value, $key3->value);
    }
}
