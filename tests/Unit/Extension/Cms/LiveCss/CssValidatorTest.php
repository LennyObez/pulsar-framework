<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\LiveCss\CssValidator;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;

#[CoversClass(CssValidationResult::class)]
#[CoversClass(CssValidator::class)]
final class CssValidatorTest extends TestCase
{
    // ── Rejects @import ─────────────────────────────────────────────

    #[Test]
    #[DataProvider('importProvider')]
    public function rejectsAtImport(string $css): void
    {
        $validator = $this->createValidator();
        $result = $validator->validate($css);

        self::assertFalse($result->isValid);
        self::assertNotEmpty($result->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function importProvider(): iterable
    {
        yield '@import url()' => ['@import url("https://evil.com/hack.css");'];
        yield '@import string' => ['@import "https://evil.com/hack.css";'];
        yield '@import with space' => ['@import  url( "https://evil.com" );'];
    }

    // ── Rejects expression() ────────────────────────────────────────

    #[Test]
    public function rejectsExpression(): void
    {
        $result = $this->createValidator()->validate('body { width: expression(document.body.clientWidth); }');

        self::assertFalse($result->isValid);
    }

    // ── Rejects external url() ──────────────────────────────────────

    #[Test]
    #[DataProvider('externalUrlProvider')]
    public function rejectsExternalUrl(string $css): void
    {
        $result = $this->createValidator()->validate($css);

        self::assertFalse($result->isValid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function externalUrlProvider(): iterable
    {
        yield 'http://' => ['body { background: url(http://evil.com/img.png); }'];
        yield 'https://' => ['body { background: url(https://evil.com/img.png); }'];
        yield 'protocol-relative //' => ['body { background: url(//evil.com/img.png); }'];
        yield 'data:' => ['body { background: url(data:text/html,<script>alert(1)</script>); }'];
    }

    // ── Rejects javascript: in values ───────────────────────────────

    #[Test]
    public function rejectsJavascriptInValues(): void
    {
        $result = $this->createValidator()->validate('body { background: url(javascript:alert(1)); }');

        self::assertFalse($result->isValid);
    }

    // ── Rejects browser-specific dangerous properties ───────────────

    #[Test]
    #[DataProvider('dangerousPropertyProvider')]
    public function rejectsDangerousProperties(string $css): void
    {
        $result = $this->createValidator()->validate($css);

        self::assertFalse($result->isValid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousPropertyProvider(): iterable
    {
        yield '-moz-binding' => ['body { -moz-binding: url("xbl-binding.xml#test"); }'];
        yield 'behavior' => ['body { behavior: url(script.htc); }'];
        yield '-o-link' => ['a { -o-link: attr(href); }'];
    }

    // ── Accepts valid CSS ───────────────────────────────────────────

    #[Test]
    #[DataProvider('validCssProvider')]
    public function acceptsValidCss(string $css): void
    {
        $result = $this->createValidator()->validate($css);

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validCssProvider(): iterable
    {
        yield 'color' => ['body { color: #ff0000; }'];
        yield 'font' => ['p { font-family: Arial, sans-serif; font-size: 16px; }'];
        yield 'margin' => ['div { margin: 10px 20px; }'];
        yield 'flexbox' => ['.container { display: flex; justify-content: center; align-items: center; }'];
        yield 'grid' => ['.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }'];
        yield 'css variables' => [':root { --color-primary: #007bff; } .btn { color: var(--color-primary); }'];
        yield 'media query' => ['@media (max-width: 768px) { .sidebar { display: none; } }'];
    }

    // ── Bypass attempts ─────────────────────────────────────────────

    #[Test]
    #[DataProvider('bypassAttemptProvider')]
    public function rejectsBypassAttempts(string $css): void
    {
        $result = $this->createValidator()->validate($css);

        self::assertFalse($result->isValid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bypassAttemptProvider(): iterable
    {
        yield 'uppercase @IMPORT' => ['@IMPORT url("https://evil.com");'];
        yield 'mixed case @ImPoRt' => ['@ImPoRt "https://evil.com";'];
        yield 'comment split ex/**/pression' => ['body { width: ex/**/pression(1); }'];
        yield 'backslash escape \\65xpression' => ['body { width: \\65xpression(1); }'];
        yield 'uppercase EXPRESSION' => ['body { width: EXPRESSION(document.body.clientWidth); }'];
        yield 'javascript with spaces' => ['body { background: url( javascript : alert(1) ); }'];
    }

    // ── Size limit ─────────────────────────────────────────────────

    #[Test]
    public function rejectsOversizedInput(): void
    {
        $result = $this->createValidator()->validate(str_repeat('a', 524_289));

        self::assertFalse($result->isValid);
        self::assertStringContainsString('512 KB', $result->errors[0]);
        self::assertSame('', $result->sanitizedCss);
    }

    // ── @charset ─────────────────────────────────────────────────────

    #[Test]
    public function rejectsAtCharset(): void
    {
        $result = $this->createValidator()->validate('@charset "UTF-8";');

        self::assertFalse($result->isValid);
        self::assertStringContainsString('@charset', $result->errors[0]);
    }

    // ── vbscript: ────────────────────────────────────────────────────

    #[Test]
    public function rejectsVbscript(): void
    {
        $result = $this->createValidator()->validate('body { background: url(vbscript:run); }');

        self::assertFalse($result->isValid);
    }

    // ── Sanitized CSS output ─────────────────────────────────────────

    #[Test]
    public function sanitizedCssRemovesBadLinesKeepsGood(): void
    {
        $css = "body { color: red; }\n@import url(\"evil.css\");\np { margin: 0; }";
        $result = $this->createValidator()->validate($css);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('body { color: red; }', $result->sanitizedCss);
        self::assertStringContainsString('p { margin: 0; }', $result->sanitizedCss);
        self::assertStringNotContainsString('@import', $result->sanitizedCss);
    }

    // ── Null byte stripping ──────────────────────────────────────────

    #[Test]
    public function stripsNullBytes(): void
    {
        $css = "body { width: ex\x00pression(1); }";
        $result = $this->createValidator()->validate($css);

        self::assertFalse($result->isValid);
    }

    private function createValidator(): CssValidatorInterface
    {
        return new CssValidator();
    }
}
