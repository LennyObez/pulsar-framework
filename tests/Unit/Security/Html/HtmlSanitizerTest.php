<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Html\HtmlSanitizer;
use Pulsar\Security\Html\HtmlSanitizerPolicy;

#[CoversClass(HtmlSanitizer::class)]
#[CoversClass(HtmlSanitizerPolicy::class)]
final class HtmlSanitizerTest extends TestCase
{
    #[Test]
    public function unwrapsElementsOutsideTheAllowlistButKeepsTheirText(): void
    {
        $sanitizer = new HtmlSanitizer(new HtmlSanitizerPolicy(allowedElements: ['p' => []]));

        $out = $sanitizer->sanitize('<p>kept</p><span>inline</span><marquee>scroll</marquee>');

        self::assertStringNotContainsString('<span', $out);
        self::assertStringNotContainsString('<marquee', $out);
        self::assertStringContainsString('<p>kept</p>', $out);
        self::assertStringContainsString('inline', $out);
        self::assertStringContainsString('scroll', $out);
    }

    #[Test]
    public function hrefSchemeAllowlistIsEnforced(): void
    {
        // Policy permits only https on links.
        $sanitizer = new HtmlSanitizer(new HtmlSanitizerPolicy(
            allowedElements: ['a' => ['href', 'rel']],
            hrefSchemes: ['https'],
        ));

        self::assertStringContainsString('https://ok.example', $sanitizer->sanitize('<a href="https://ok.example">x</a>'));
        self::assertStringNotContainsString('http://no.example', $sanitizer->sanitize('<a href="http://no.example">x</a>'));
    }

    #[Test]
    public function imgRelativePrefixIsEnforced(): void
    {
        $sanitizer = new HtmlSanitizer(new HtmlSanitizerPolicy(
            allowedElements: ['img' => ['src']],
            imgRelativePrefix: '/media/',
        ));

        self::assertStringContainsString('/media/ok.png', $sanitizer->sanitize('<img src="/media/ok.png">'));
        self::assertStringNotContainsString('/secret/', $sanitizer->sanitize('<img src="/secret/x.png">'));
    }

    #[Test]
    public function onBypassFiresAndOutputIsEscapedWhenResidueIsDangerous(): void
    {
        $bypassedWith = null;
        $sanitizer = new HtmlSanitizer(
            new HtmlSanitizerPolicy(allowedElements: ['code' => []]),
            function (string $original) use (&$bypassedWith): void {
                $bypassedWith = $original;
            },
        );

        // The defense-in-depth scan trips on residual "javascript:" text and
        // falls back to escaped plaintext.
        $input = '<code>javascript:alert(1)</code>';
        $out = $sanitizer->sanitize($input);

        self::assertSame($input, $bypassedWith, 'onBypass must receive the original input');
        self::assertStringNotContainsString('<code>', $out);
        self::assertStringContainsString('&lt;code&gt;', $out);
    }

    #[Test]
    public function emptyInputReturnsEmpty(): void
    {
        $sanitizer = new HtmlSanitizer(new HtmlSanitizerPolicy(allowedElements: ['p' => []]));

        self::assertSame('', $sanitizer->sanitize(''));
    }
}
