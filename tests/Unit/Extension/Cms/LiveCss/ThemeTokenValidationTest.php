<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\LiveCss\ThemeTokenResolver;
use Pulsar\Extension\Cms\LiveCss\ThemeToken;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

#[CoversClass(ThemeTokenResolver::class)]
#[CoversClass(ThemeToken::class)]
final class ThemeTokenValidationTest extends TestCase
{
    private ThemeTokenResolver $resolver;

    protected function setUp(): void
    {
        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $this->resolver = new ThemeTokenResolver($repo);
    }

    // --- Color validation ---

    #[Test]
    #[DataProvider('validColorProvider')]
    public function validateAcceptsValidColors(string $value): void
    {
        $token = new ThemeToken('--color', 'color', '#000', 'Color', 'Colors');

        self::assertTrue($this->resolver->validateTokenValue($token, $value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validColorProvider(): iterable
    {
        yield 'hex 3' => ['#fff'];
        yield 'hex 6' => ['#ff0000'];
        yield 'hex 8' => ['#ff000080'];
        yield 'rgb' => ['rgb(255, 128, 0)'];
        yield 'rgba' => ['rgba(255, 128, 0, 0.5)'];
        yield 'hsl' => ['hsl(120, 50%, 50%)'];
        yield 'hsla' => ['hsla(120, 50%, 50%, 0.8)'];
    }

    #[Test]
    #[DataProvider('invalidColorProvider')]
    public function validateRejectsInvalidColors(string $value): void
    {
        $token = new ThemeToken('--color', 'color', '#000', 'Color', 'Colors');

        self::assertFalse($this->resolver->validateTokenValue($token, $value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColorProvider(): iterable
    {
        yield 'plain text' => ['red'];
        yield 'url injection' => ['url(javascript:alert(1))'];
        yield 'expression injection' => ['expression(alert(1))'];
        yield 'javascript protocol' => ['javascript:void(0)'];
        yield 'hex too short' => ['#ff'];
        yield 'hex too long' => ['#ff00ff00ff'];
    }

    // --- Font validation ---

    #[Test]
    public function validateAcceptsValidFont(): void
    {
        $token = new ThemeToken('--font', 'font', 'Arial', 'Font', 'Typography');

        self::assertTrue($this->resolver->validateTokenValue($token, 'Helvetica, sans-serif'));
    }

    #[Test]
    public function validateRejectsFontWithUrl(): void
    {
        $token = new ThemeToken('--font', 'font', 'Arial', 'Font', 'Typography');

        self::assertFalse($this->resolver->validateTokenValue($token, 'url(http://evil.com/font.woff)'));
    }

    #[Test]
    public function validateFontWithAllowedValues(): void
    {
        $token = new ThemeToken(
            '--font',
            'font',
            'Inter',
            'Font',
            'Typography',
            ['allowed_values' => ['Inter', 'Roboto', 'Open Sans']],
        );

        self::assertTrue($this->resolver->validateTokenValue($token, 'Roboto'));
        self::assertFalse($this->resolver->validateTokenValue($token, 'Comic Sans'));
    }

    // --- Size validation ---

    #[Test]
    #[DataProvider('validSizeProvider')]
    public function validateAcceptsValidSizes(string $value): void
    {
        $token = new ThemeToken('--size', 'size', '16px', 'Size', 'Layout');

        self::assertTrue($this->resolver->validateTokenValue($token, $value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validSizeProvider(): iterable
    {
        yield 'px' => ['16px'];
        yield 'rem' => ['1.5rem'];
        yield 'em' => ['2em'];
        yield 'vh' => ['100vh'];
        yield 'vw' => ['50vw'];
        yield 'percent' => ['75%'];
        yield 'negative' => ['-1px'];
    }

    #[Test]
    #[DataProvider('invalidSizeProvider')]
    public function validateRejectsInvalidSizes(string $value): void
    {
        $token = new ThemeToken('--size', 'size', '16px', 'Size', 'Layout');

        self::assertFalse($this->resolver->validateTokenValue($token, $value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSizeProvider(): iterable
    {
        yield 'no unit' => ['16'];
        yield 'invalid unit' => ['16dp'];
        yield 'text' => ['large'];
    }

    #[Test]
    public function validateSizeRespectsMinConstraint(): void
    {
        $token = new ThemeToken('--gap', 'size', '8px', 'Gap', 'Layout', ['min' => 0]);

        self::assertTrue($this->resolver->validateTokenValue($token, '0px'));
        self::assertTrue($this->resolver->validateTokenValue($token, '10px'));
        self::assertFalse($this->resolver->validateTokenValue($token, '-1px'));
    }

    #[Test]
    public function validateSizeRespectsMaxConstraint(): void
    {
        $token = new ThemeToken('--width', 'size', '100px', 'Width', 'Layout', ['max' => 200]);

        self::assertTrue($this->resolver->validateTokenValue($token, '200px'));
        self::assertFalse($this->resolver->validateTokenValue($token, '201px'));
    }

    #[Test]
    public function validateSizeRespectsMinAndMaxConstraints(): void
    {
        $token = new ThemeToken('--radius', 'size', '4px', 'Radius', 'Layout', ['min' => 0, 'max' => 50]);

        self::assertTrue($this->resolver->validateTokenValue($token, '25px'));
        self::assertFalse($this->resolver->validateTokenValue($token, '51px'));
        self::assertFalse($this->resolver->validateTokenValue($token, '-1px'));
    }

    // --- String validation ---

    #[Test]
    public function validateAcceptsPlainString(): void
    {
        $token = new ThemeToken('--custom', 'string', '', 'Custom', 'General');

        self::assertTrue($this->resolver->validateTokenValue($token, 'any safe text'));
    }

    #[Test]
    public function validateRejectsStringWithDangerousConstructs(): void
    {
        $token = new ThemeToken('--custom', 'string', '', 'Custom', 'General');

        self::assertFalse($this->resolver->validateTokenValue($token, 'url(http://evil.com)'));
        self::assertFalse($this->resolver->validateTokenValue($token, 'expression(alert(1))'));
        self::assertFalse($this->resolver->validateTokenValue($token, 'javascript:void(0)'));
    }

    // --- Unknown type ---

    #[Test]
    public function validateRejectsUnknownType(): void
    {
        $token = new ThemeToken('--unknown', 'gradient', '', 'Unknown', 'General');

        self::assertFalse($this->resolver->validateTokenValue($token, 'linear-gradient(red, blue)'));
    }

    // --- ThemeToken construction ---

    #[Test]
    public function themeTokenConstructionDefaults(): void
    {
        $token = new ThemeToken('--color', 'color', '#000', 'Primary Color', 'Colors');

        self::assertSame('--color', $token->name);
        self::assertSame('color', $token->type);
        self::assertSame('#000', $token->default);
        self::assertSame('Primary Color', $token->label);
        self::assertSame('Colors', $token->group);
        self::assertSame([], $token->constraints);
    }

    #[Test]
    public function themeTokenConstructionWithConstraints(): void
    {
        $token = new ThemeToken('--s', 'size', '8px', 'Size', 'Layout', ['min' => 0, 'max' => 100]);

        self::assertSame(0, $token->constraints['min']);
        self::assertSame(100, $token->constraints['max']);
    }
}
