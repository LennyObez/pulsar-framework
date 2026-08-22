<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Escaping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../src/View/Escaping/helpers.php';

final class HelpersTest extends TestCase
{
    #[Test]
    public function urlFunctionEscapesUnsafeSchemes(): void
    {
        self::assertSame('', url('javascript:alert(1)'));
    }

    #[Test]
    public function urlFunctionPassesSafeUrls(): void
    {
        self::assertStringContainsString('https', url('https://example.com/path'));
    }

    #[Test]
    public function attrFunctionEncodesSpecialChars(): void
    {
        $escaped = attr('<script>');

        self::assertStringNotContainsString('<', $escaped);
        self::assertStringNotContainsString('>', $escaped);
    }

    #[Test]
    public function attrFunctionPassesAlphanumeric(): void
    {
        self::assertSame('hello123', attr('hello123'));
    }

    #[Test]
    public function jsFunctionEscapesHtmlTags(): void
    {
        $escaped = js('<script>');

        self::assertStringNotContainsString('<script>', $escaped);
    }

    #[Test]
    public function jsFunctionHandlesPlainText(): void
    {
        self::assertSame('hello', js('hello'));
    }

    #[Test]
    public function cssFunctionEscapesSpecialChars(): void
    {
        $escaped = css('expression(');

        self::assertStringNotContainsString('(', $escaped);
    }

    #[Test]
    public function cssFunctionPassesAlphanumeric(): void
    {
        self::assertSame('red', css('red'));
    }
}
