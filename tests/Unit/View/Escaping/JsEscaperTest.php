<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Escaping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Escaping\JsEscaper;

#[CoversClass(JsEscaper::class)]
final class JsEscaperTest extends TestCase
{
    #[Test]
    public function escapesHtmlTagsForScriptContext(): void
    {
        $escaper = new JsEscaper();

        $result = $escaper->escape('<script>');

        self::assertStringNotContainsString('<', $result);
        self::assertStringNotContainsString('>', $result);
    }

    #[Test]
    public function escapesQuotes(): void
    {
        $escaper = new JsEscaper();

        $result = $escaper->escape('He said "hello"');

        self::assertStringNotContainsString('"', $result);
    }

    #[Test]
    public function plainTextPassesThrough(): void
    {
        $escaper = new JsEscaper();

        self::assertSame('Hello World', $escaper->escape('Hello World'));
    }

    #[Test]
    public function escapesAmpersand(): void
    {
        $escaper = new JsEscaper();

        $result = $escaper->escape('a & b');

        self::assertStringNotContainsString('&', $result);
    }
}
