<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Filter\Trim;

#[CoversClass(Trim::class)]
final class TrimTest extends TestCase
{
    private Trim $filter;

    protected function setUp(): void
    {
        $this->filter = new Trim();
    }

    #[Test]
    public function trimsWhitespace(): void
    {
        self::assertSame('hello', $this->filter->apply('  hello  '));
    }

    #[Test]
    public function trimsNewlines(): void
    {
        self::assertSame('hello', $this->filter->apply("\nhello\n"));
    }

    #[Test]
    public function passesNonStringThrough(): void
    {
        self::assertSame(42, $this->filter->apply(42));
        self::assertNull($this->filter->apply(null));
        self::assertSame([], $this->filter->apply([]));
    }

    #[Test]
    public function emptyStringRemainsEmpty(): void
    {
        self::assertSame('', $this->filter->apply(''));
    }
}
