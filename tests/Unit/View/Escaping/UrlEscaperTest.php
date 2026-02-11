<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Escaping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Escaping\UrlEscaper;

#[CoversClass(UrlEscaper::class)]
final class UrlEscaperTest extends TestCase
{
    private UrlEscaper $escaper;

    protected function setUp(): void
    {
        $this->escaper = new UrlEscaper();
    }

    #[Test]
    public function safeUrlPassesThrough(): void
    {
        $url = 'https://example.com/path?query=value#fragment';

        self::assertSame($url, $this->escaper->escape($url));
    }

    #[Test]
    public function blocksDangerousJavascriptScheme(): void
    {
        self::assertSame('', $this->escaper->escape('javascript:alert(1)'));
    }

    #[Test]
    public function blocksDangerousDataScheme(): void
    {
        self::assertSame('', $this->escaper->escape('data:text/html,<script>alert(1)</script>'));
    }

    #[Test]
    public function blocksDangerousVbscriptScheme(): void
    {
        self::assertSame('', $this->escaper->escape('vbscript:MsgBox("xss")'));
    }

    #[Test]
    public function blocksCaseInsensitiveSchemes(): void
    {
        self::assertSame('', $this->escaper->escape('JaVaScRiPt:alert(1)'));
    }

    #[Test]
    public function blocksSchemeWithWhitespace(): void
    {
        self::assertSame('', $this->escaper->escape("java\tscript:alert(1)"));
    }

    #[Test]
    public function relativePath(): void
    {
        self::assertSame('/dashboard/settings', $this->escaper->escape('/dashboard/settings'));
    }

    #[Test]
    public function encodesUnsafeCharacters(): void
    {
        $result = $this->escaper->escape('/path with spaces');

        self::assertStringContainsString('%20', $result);
    }
}
