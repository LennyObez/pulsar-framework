<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\QrCodeEncoder;

use function strlen;

#[CoversClass(QrCodeEncoder::class)]
final class QrCodeEncoderTest extends TestCase
{
    private QrCodeEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new QrCodeEncoder();
    }

    // ── Basic encoding ──────────────────────────────────────────────────

    #[Test]
    public function encodeReturnsValidSvg(): void
    {
        $svg = $this->encoder->encode('Hello');

        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function encodeContainsWhiteBackgroundRect(): void
    {
        $svg = $this->encoder->encode('Test');

        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('fill="#fff"', $svg);
    }

    #[Test]
    public function encodeContainsDarkModulesPath(): void
    {
        $svg = $this->encoder->encode('QR');

        self::assertStringContainsString('<path d="', $svg);
        self::assertStringContainsString('fill="#000"', $svg);
    }

    #[Test]
    public function encodeProducesDeterministicOutput(): void
    {
        $svg1 = $this->encoder->encode('deterministic');
        $svg2 = $this->encoder->encode('deterministic');

        self::assertSame($svg1, $svg2);
    }

    // ── Module size and quiet zone ──────────────────────────────────────

    #[Test]
    public function customModuleSizeAffectsViewBox(): void
    {
        $svgDefault = $this->encoder->encode('A', moduleSize: 4);
        $svgLarge = $this->encoder->encode('A', moduleSize: 8);

        // Larger module size produces a larger viewBox
        self::assertNotSame($svgDefault, $svgLarge);

        // Extract viewBox width from SVG
        self::assertSame(1, preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svgDefault, $matchDefault));
        self::assertSame(1, preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svgLarge, $matchLarge));
        self::assertTrue(isset($matchDefault[1], $matchLarge[1]));

        self::assertGreaterThan((int) $matchDefault[1], (int) $matchLarge[1]);
    }

    #[Test]
    public function customQuietZoneAffectsViewBox(): void
    {
        $svgSmallZone = $this->encoder->encode('A', quietZone: 2);
        $svgLargeZone = $this->encoder->encode('A', quietZone: 8);

        self::assertSame(1, preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svgSmallZone, $matchSmall));
        self::assertSame(1, preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svgLargeZone, $matchLarge));
        self::assertTrue(isset($matchSmall[1], $matchLarge[1]));

        self::assertGreaterThan((int) $matchSmall[1], (int) $matchLarge[1]);
    }

    #[Test]
    public function zeroQuietZoneProducesValidSvg(): void
    {
        $svg = $this->encoder->encode('NoQuiet', quietZone: 0);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    // ── Data variations ─────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validDataProvider')]
    public function encodeHandlesVariousInputs(string $data): void
    {
        $svg = $this->encoder->encode($data);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('fill="#000"', $svg);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validDataProvider(): iterable
    {
        yield 'single character' => ['A'];
        yield 'short text' => ['Hello'];
        yield 'url' => ['https://example.com'];
        yield 'numeric' => ['1234567890'];
        yield 'special characters' => ['!@#$%^&*()'];
        yield 'utf8 text' => ['Bonjour le monde'];
        yield 'mixed' => ['User: john@example.com (ID: 42)'];
    }

    #[Test]
    public function differentDataProducesDifferentSvg(): void
    {
        $svg1 = $this->encoder->encode('Hello');
        $svg2 = $this->encoder->encode('World');

        self::assertNotSame($svg1, $svg2);
    }

    #[Test]
    public function emptyStringProducesValidSvg(): void
    {
        $svg = $this->encoder->encode('');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    // ── Version selection ───────────────────────────────────────────────

    #[Test]
    public function shortDataUsesSmallVersion(): void
    {
        $svgShort = $this->encoder->encode('Hi');
        $svgLong = $this->encoder->encode(str_repeat('A', 30));

        // Longer data requires higher version = larger QR code = larger SVG
        self::assertGreaterThan(strlen($svgShort), strlen($svgLong));
    }

    #[Test]
    public function dataTooLargeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data too large for QR code');

        // Version 10 supports a maximum of ~174 bytes in byte mode with EC level M
        // Use a string large enough to exceed this
        $this->encoder->encode(str_repeat('X', 500));
    }

    // ── SVG structure ───────────────────────────────────────────────────

    #[Test]
    public function svgHasCorrectWidthAndHeight(): void
    {
        $svg = $this->encoder->encode('Test', moduleSize: 4, quietZone: 4);

        self::assertSame(1, preg_match('/width="(\d+)"/', $svg, $widthMatch));
        self::assertSame(1, preg_match('/height="(\d+)"/', $svg, $heightMatch));
        self::assertTrue(isset($widthMatch[1], $heightMatch[1]));

        // Width and height should be equal (QR is always square)
        self::assertSame($widthMatch[1], $heightMatch[1]);
    }

    #[Test]
    public function svgViewBoxMatchesWidthHeight(): void
    {
        $svg = $this->encoder->encode('ABC');

        self::assertSame(1, preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $viewBox));
        self::assertSame(1, preg_match('/width="(\d+)"/', $svg, $width));
        self::assertSame(1, preg_match('/height="(\d+)"/', $svg, $height));
        self::assertTrue(isset($viewBox[1], $viewBox[2], $width[1], $height[1]));

        self::assertSame($viewBox[1], $width[1]);
        self::assertSame($viewBox[2], $height[1]);
    }

    #[Test]
    public function svgPathUsesCompactRepresentation(): void
    {
        $svg = $this->encoder->encode('compact');

        // Path should use h/v relative commands for efficiency
        self::assertMatchesRegularExpression('/M\d+,\d+h\d+v\d+h-\d+z/', $svg);
    }

    // ── Module size 1 ───────────────────────────────────────────────────

    #[Test]
    public function moduleSizeOneProducesMinimalSvg(): void
    {
        $svg = $this->encoder->encode('Min', moduleSize: 1, quietZone: 0);

        self::assertStringStartsWith('<svg', $svg);
        // Version 1 = 21x21 modules, so viewBox should be 21x21
        self::assertSame(1, preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $viewBox));
        self::assertTrue(isset($viewBox[1]));
        // Size should be exactly the QR module count
        $size = (int) $viewBox[1];
        self::assertSame(21, $size);
    }
}
