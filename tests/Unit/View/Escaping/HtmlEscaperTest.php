<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Escaping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Escaping\HtmlEscaper;

#[CoversClass(HtmlEscaper::class)]
final class HtmlEscaperTest extends TestCase
{
    #[Test]
    public function escapesHtmlEntities(): void
    {
        $escaper = new HtmlEscaper();

        $result = $escaper->escape('<script>alert("xss")</script>');

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;', $result);
        self::assertStringContainsString('&quot;', $result);
    }

    #[Test]
    public function escapesSingleQuotes(): void
    {
        $escaper = new HtmlEscaper();

        $result = $escaper->escape("it's a test");

        self::assertStringContainsString('&#039;', $result);
    }

    #[Test]
    public function safeContentPassesThrough(): void
    {
        $escaper = new HtmlEscaper();

        self::assertSame('Hello World', $escaper->escape('Hello World'));
    }

    #[Test]
    public function ampersandIsEscaped(): void
    {
        $escaper = new HtmlEscaper();

        self::assertSame('a &amp; b', $escaper->escape('a & b'));
    }
}
