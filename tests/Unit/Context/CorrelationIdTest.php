<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\Exception\ContextException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function strlen;

#[CoversClass(CorrelationId::class)]
final class CorrelationIdTest extends TestCase
{
    #[Test]
    public function generateReturns32CharHex(): void
    {
        $id = CorrelationId::generate();

        self::assertSame(32, strlen($id->value));
        self::assertTrue(ctype_xdigit($id->value));
    }

    #[Test]
    public function generateProducesUniqueValues(): void
    {
        $a = CorrelationId::generate();
        $b = CorrelationId::generate();

        self::assertNotSame($a->value, $b->value);
    }

    #[Test]
    public function generateUsesInjectedRandomizer(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(42));
        $id = CorrelationId::generate($randomizer);

        self::assertSame(32, strlen($id->value));
        self::assertTrue(ctype_xdigit($id->value));
    }

    #[Test]
    public function fromStringRoundtrips(): void
    {
        $hex = str_repeat('ab', 16);
        $id = CorrelationId::fromString($hex);

        self::assertSame($hex, $id->value);
        self::assertSame($hex, $id->toString());
        self::assertSame($hex, (string) $id);
    }

    #[Test]
    public function constructorNormalizesToLowercase(): void
    {
        $id = new CorrelationId(str_repeat('AB', 16));

        self::assertSame(str_repeat('ab', 16), $id->value);
    }

    #[Test]
    public function constructorRejectsInvalidLength(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessageIsOrContains('Invalid correlation ID');

        new CorrelationId('abc');
    }

    #[Test]
    public function constructorRejectsNonHex(): void
    {
        $this->expectException(ContextException::class);

        new CorrelationId(str_repeat('zz', 16));
    }
}
