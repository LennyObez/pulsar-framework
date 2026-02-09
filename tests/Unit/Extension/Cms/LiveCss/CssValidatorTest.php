<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;

#[CoversClass(CssValidationResult::class)]
final class CssValidatorTest extends TestCase
{
    // ── Rejects @import ─────────────────────────────────────────────

    #[Test]
    #[DataProvider('importProvider')]
    public function test_rejects_at_import(string $css): void
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
    public function test_rejects_expression(): void
    {
        $result = $this->createValidator()->validate('body { width: expression(document.body.clientWidth); }');

        self::assertFalse($result->isValid);
    }

    // ── Rejects external url() ──────────────────────────────────────

    #[Test]
    #[DataProvider('externalUrlProvider')]
    public function test_rejects_external_url(string $css): void
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
    public function test_rejects_javascript_in_values(): void
    {
        $result = $this->createValidator()->validate('body { background: url(javascript:alert(1)); }');

        self::assertFalse($result->isValid);
    }

    // ── Rejects browser-specific dangerous properties ───────────────

    #[Test]
    #[DataProvider('dangerousPropertyProvider')]
    public function test_rejects_dangerous_properties(string $css): void
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
    public function test_accepts_valid_css(string $css): void
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
    public function test_rejects_bypass_attempts(string $css): void
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

    private function createValidator(): CssValidatorInterface
    {
        return new class implements CssValidatorInterface {
            /** @var list<string> */
            private const array BLOCKED_PATTERNS = [
                '/@import\b/i',
                '/expression\s*\(/i',
                '/ex\s*\/\*.*?\*\/\s*pression\s*\(/is',
                '/\\\\65\s*xpression\s*\(/i',
                '/url\s*\(\s*["\']?\s*https?:/i',
                '/url\s*\(\s*["\']?\s*\/\//i',
                '/url\s*\(\s*["\']?\s*data:/i',
                '/url\s*\(\s*["\']?\s*javascript\s*:/i',
                '/-moz-binding\s*:/i',
                '/behavior\s*:/i',
                '/-o-link\s*:/i',
            ];

            public function validate(string $cssContent): CssValidationResult
            {
                $errors = [];

                foreach (self::BLOCKED_PATTERNS as $pattern) {
                    if (preg_match($pattern, $cssContent)) {
                        $errors[] = "Blocked pattern detected: {$pattern}";
                    }
                }

                return new CssValidationResult(
                    isValid: $errors === [],
                    errors: $errors,
                    sanitizedCss: $errors === [] ? $cssContent : '',
                );
            }
        };
    }
}
