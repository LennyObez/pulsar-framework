<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\ThemeToken;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;

use function in_array;
use function is_array;
use function strlen;

#[CoversClass(ThemeToken::class)]
final class ThemeTokenValidatorTest extends TestCase
{
    // ── Color validation: valid ─────────────────────────────────────

    #[Test]
    #[DataProvider('validColorProvider')]
    public function test_valid_color_values(string $value): void
    {
        $resolver = $this->createResolver();
        $token = new ThemeToken('--color-primary', 'color', '#007bff', 'Primary Color', 'Colors');

        self::assertTrue($resolver->validateTokenValue($token, $value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validColorProvider(): iterable
    {
        yield 'hex 3' => ['#f00'];
        yield 'hex 6' => ['#ff0000'];
        yield 'hex 8 (with alpha)' => ['#ff000080'];
        yield 'rgb' => ['rgb(255, 0, 0)'];
        yield 'rgba' => ['rgba(255, 0, 0, 0.5)'];
        yield 'hsl' => ['hsl(0, 100%, 50%)'];
        yield 'hsla' => ['hsla(0, 100%, 50%, 0.5)'];
        yield 'named color' => ['red'];
    }

    // ── Color validation: rejected ──────────────────────────────────

    #[Test]
    #[DataProvider('invalidColorProvider')]
    public function test_invalid_color_values(string $value): void
    {
        $resolver = $this->createResolver();
        $token = new ThemeToken('--color-primary', 'color', '#007bff', 'Primary Color', 'Colors');

        self::assertFalse($resolver->validateTokenValue($token, $value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColorProvider(): iterable
    {
        yield 'url()' => ['url(https://evil.com)'];
        yield 'expression()' => ['expression(document.cookie)'];
        yield 'javascript:' => ['javascript:alert(1)'];
    }

    // ── Font validation with allowed_values ─────────────────────────

    #[Test]
    public function test_font_validation_with_allowed_values(): void
    {
        $resolver = $this->createResolver();
        $token = new ThemeToken(
            '--font-body',
            'font',
            'Arial, sans-serif',
            'Body Font',
            'Typography',
            ['allowed_values' => ['Arial, sans-serif', 'Georgia, serif', 'monospace']],
        );

        self::assertTrue($resolver->validateTokenValue($token, 'Arial, sans-serif'));
        self::assertTrue($resolver->validateTokenValue($token, 'Georgia, serif'));
        self::assertFalse($resolver->validateTokenValue($token, 'Comic Sans MS'));
    }

    // ── Size validation with min/max range ──────────────────────────

    #[Test]
    public function test_size_validation_within_range(): void
    {
        $resolver = $this->createResolver();
        $token = new ThemeToken(
            '--font-size',
            'size',
            '16px',
            'Font Size',
            'Typography',
            ['min' => 12, 'max' => 48, 'unit' => 'px'],
        );

        self::assertTrue($resolver->validateTokenValue($token, '16px'));
        self::assertTrue($resolver->validateTokenValue($token, '12px'));
        self::assertTrue($resolver->validateTokenValue($token, '48px'));
        self::assertFalse($resolver->validateTokenValue($token, '8px'));
        self::assertFalse($resolver->validateTokenValue($token, '96px'));
    }

    // ── Invalid unit rejection ──────────────────────────────────────

    #[Test]
    public function test_invalid_unit_rejected(): void
    {
        $resolver = $this->createResolver();
        $token = new ThemeToken(
            '--font-size',
            'size',
            '16px',
            'Font Size',
            'Typography',
            ['min' => 12, 'max' => 48, 'unit' => 'px'],
        );

        self::assertFalse($resolver->validateTokenValue($token, '16em'));
        self::assertFalse($resolver->validateTokenValue($token, '1rem'));
    }

    // ── String type accepts any value ───────────────────────────────

    #[Test]
    public function test_string_type_accepts_safe_values(): void
    {
        $resolver = $this->createResolver();
        $token = new ThemeToken('--brand-name', 'string', 'Pulsar', 'Brand Name', 'General');

        self::assertTrue($resolver->validateTokenValue($token, 'My Brand'));
    }

    private function createResolver(): ThemeTokenResolverInterface
    {
        return new class implements ThemeTokenResolverInterface {
            public function getEditableTokens(string $themeId): array
            {
                return [];
            }

            public function validateTokenValue(ThemeToken $token, string $value): bool
            {
                // Reject dangerous patterns in any type
                if (preg_match('/url\s*\(|expression\s*\(|javascript\s*:/i', $value)) {
                    return false;
                }

                return match ($token->type) {
                    'color' => $this->validateColor($value),
                    'font' => $this->validateFont($token, $value),
                    'size' => $this->validateSize($token, $value),
                    default => true,
                };
            }

            private function validateColor(string $value): bool
            {
                // Accept hex, rgb, rgba, hsl, hsla, named colors
                return (bool) preg_match(
                    '/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-zA-Z]+)$/',
                    $value,
                );
            }

            private function validateFont(ThemeToken $token, string $value): bool
            {
                $allowed = $token->constraints['allowed_values'] ?? null;

                if (is_array($allowed)) {
                    return in_array($value, $allowed, true);
                }

                return true;
            }

            private function validateSize(ThemeToken $token, string $value): bool
            {
                /** @var string $unit */
                $unit = $token->constraints['unit'] ?? 'px';
                /** @var int $min */
                $min = $token->constraints['min'] ?? 0;
                /** @var int $max */
                $max = $token->constraints['max'] ?? PHP_INT_MAX;

                if (!str_ends_with($value, $unit)) {
                    return false;
                }

                $numeric = (int) substr($value, 0, -strlen($unit));

                return $numeric >= $min && $numeric <= $max;
            }
        };
    }
}
