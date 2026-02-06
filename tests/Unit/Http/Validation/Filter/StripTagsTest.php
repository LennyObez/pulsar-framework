<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Filter\StripTags;

#[CoversClass(StripTags::class)]
final class StripTagsTest extends TestCase
{
    private StripTags $filter;

    protected function setUp(): void
    {
        $this->filter = new StripTags();
    }

    #[Test]
    public function stripsHtmlTags(): void
    {
        self::assertSame('hello', $this->filter->apply('<b>hello</b>'));
    }

    #[Test]
    public function stripsScriptTags(): void
    {
        self::assertSame('alert("xss")', $this->filter->apply('<script>alert("xss")</script>'));
    }

    #[Test]
    public function passesNonStringThrough(): void
    {
        self::assertSame(42, $this->filter->apply(42));
        self::assertNull($this->filter->apply(null));
    }

    #[Test]
    public function plainStringUnchanged(): void
    {
        self::assertSame('hello world', $this->filter->apply('hello world'));
    }
}
