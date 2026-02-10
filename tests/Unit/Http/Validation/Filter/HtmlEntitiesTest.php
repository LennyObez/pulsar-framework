<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Filter\HtmlEntities;

#[CoversClass(HtmlEntities::class)]
final class HtmlEntitiesTest extends TestCase
{
    private HtmlEntities $filter;

    protected function setUp(): void
    {
        $this->filter = new HtmlEntities();
    }

    #[Test]
    public function encodesHtmlCharacters(): void
    {
        self::assertSame('&lt;script&gt;', $this->filter->apply('<script>'));
    }

    #[Test]
    public function encodesQuotes(): void
    {
        self::assertSame('&quot;hello&quot;', $this->filter->apply('"hello"'));
    }

    #[Test]
    public function encodesAmpersand(): void
    {
        self::assertSame('a &amp; b', $this->filter->apply('a & b'));
    }

    #[Test]
    public function encodesSingleQuotes(): void
    {
        self::assertSame('it&#039;s', $this->filter->apply("it's"));
    }

    #[Test]
    public function passesNonStringThrough(): void
    {
        self::assertSame(42, $this->filter->apply(42));
        self::assertNull($this->filter->apply(null));
    }

    #[Test]
    public function plainTextUnchanged(): void
    {
        self::assertSame('hello world', $this->filter->apply('hello world'));
    }
}
