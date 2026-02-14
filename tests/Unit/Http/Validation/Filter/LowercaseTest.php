<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Filter\Lowercase;

#[CoversClass(Lowercase::class)]
final class LowercaseTest extends TestCase
{
    private Lowercase $filter;

    protected function setUp(): void
    {
        $this->filter = new Lowercase();
    }

    #[Test]
    public function convertsToLowercase(): void
    {
        self::assertSame('hello world', $this->filter->apply('HELLO WORLD'));
    }

    #[Test]
    public function handlesMixedCase(): void
    {
        self::assertSame('hello', $this->filter->apply('HeLLo'));
    }

    #[Test]
    public function passesNonStringThrough(): void
    {
        self::assertSame(42, $this->filter->apply(42));
        self::assertNull($this->filter->apply(null));
    }

    #[Test]
    public function handlesMultibyteCharacters(): void
    {
        self::assertSame('strasse', $this->filter->apply('STRASSE'));
    }
}
