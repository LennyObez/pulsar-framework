<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Filter\Uppercase;

#[CoversClass(Uppercase::class)]
final class UppercaseTest extends TestCase
{
    private Uppercase $filter;

    protected function setUp(): void
    {
        $this->filter = new Uppercase();
    }

    #[Test]
    public function convertsToUppercase(): void
    {
        self::assertSame('HELLO WORLD', $this->filter->apply('hello world'));
    }

    #[Test]
    public function handlesMixedCase(): void
    {
        self::assertSame('HELLO', $this->filter->apply('HeLLo'));
    }

    #[Test]
    public function passesNonStringThrough(): void
    {
        self::assertSame(42, $this->filter->apply(42));
        self::assertNull($this->filter->apply(null));
    }
}
