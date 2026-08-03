<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\Exception\ContextException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function strlen;

#[CoversClass(CausationId::class)]
final class CausationIdTest extends TestCase
{
    #[Test]
    public function generateReturns32CharHex(): void
    {
        $id = CausationId::generate();

        self::assertSame(32, strlen($id->value));
        self::assertTrue(ctype_xdigit($id->value));
    }

    #[Test]
    public function generateProducesUniqueValues(): void
    {
        $a = CausationId::generate();
        $b = CausationId::generate();

        self::assertNotSame($a->value, $b->value);
    }

    #[Test]
    public function generateUsesInjectedRandomizer(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(42));
        $id = CausationId::generate($randomizer);

        self::assertSame(32, strlen($id->value));
        self::assertTrue(ctype_xdigit($id->value));
    }

    #[Test]
    public function fromStringRoundtrips(): void
    {
        $hex = str_repeat('cd', 16);
        $id = CausationId::fromString($hex);

        self::assertSame($hex, $id->value);
        self::assertSame($hex, $id->toString());
        self::assertSame($hex, (string) $id);
    }

    #[Test]
    public function constructorNormalizesToLowercase(): void
    {
        $id = new CausationId(str_repeat('EF', 16));

        self::assertSame(str_repeat('ef', 16), $id->value);
    }

    #[Test]
    public function constructorRejectsInvalidLength(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessageIsOrContains('Invalid causation ID');

        new CausationId('abc');
    }

    #[Test]
    public function constructorRejectsNonHex(): void
    {
        $this->expectException(ContextException::class);

        new CausationId(str_repeat('zz', 16));
    }
}
