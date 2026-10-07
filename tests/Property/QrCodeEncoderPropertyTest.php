<?php

declare(strict_types=1);

namespace Pulsar\Tests\Property;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\QrCodeEncoder;

use function md5;
use function preg_match;
use function str_repeat;
use function substr;

/**
 * Property-based tests for QrCodeEncoder.
 *
 * Verifies invariants that must hold for ANY valid input:
 * - Output is always well-formed SVG
 * - SVG always contains the required structural elements
 * - Different inputs produce different outputs
 */
#[CoversClass(QrCodeEncoder::class)]
#[Group('property')]
final class QrCodeEncoderPropertyTest extends TestCase
{
    private QrCodeEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new QrCodeEncoder();
    }

    /**
     * For any valid string under the max length, encoding produces valid SVG.
     *
     * The SVG must:
     * 1. Start with <svg
     * 2. End with </svg>
     * 3. Contain xmlns declaration
     * 4. Contain a viewBox attribute
     * 5. Contain a white background rect
     */
    #[Test]
    #[DataProvider('validInputStrings')]
    public function encodingAnyValidStringProducesWellFormedSvg(string $input): void
    {
        $svg = $this->encoder->encode($input);

        self::assertStringStartsWith('<svg ', $svg, 'SVG output must start with <svg tag');
        self::assertStringEndsWith('</svg>', $svg, 'SVG output must end with </svg>');
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg, 'SVG must declare xmlns');
        self::assertStringContainsString('viewBox="0 0', $svg, 'SVG must contain viewBox');
        self::assertStringContainsString('<rect', $svg, 'SVG must contain a background rect');
        self::assertStringContainsString('fill="#fff"', $svg, 'SVG must have white background');
    }

    /**
     * For any non-empty valid input, the SVG contains a dark module path.
     */
    #[Test]
    #[DataProvider('nonEmptyValidStrings')]
    public function nonEmptyInputProducesDarkModules(string $input): void
    {
        $svg = $this->encoder->encode($input);

        self::assertStringContainsString('<path d="', $svg, 'Non-empty input must produce dark modules');
        self::assertStringContainsString('fill="#000"', $svg, 'Dark modules must be black');
    }

    /**
     * Different inputs produce different SVG outputs.
     */
    #[Test]
    public function differentInputsProduceDifferentOutputs(): void
    {
        $pairs = [
            ['hello', 'world'],
            ['A', 'B'],
            ['test123', 'test456'],
            ['foo', 'foobar'],
        ];

        foreach ($pairs as [$a, $b]) {
            $svgA = $this->encoder->encode($a);
            $svgB = $this->encoder->encode($b);

            self::assertNotSame($svgA, $svgB, "'{$a}' and '{$b}' must produce different SVG");
        }
    }

    /**
     * Encoding the same input twice produces identical output (determinism).
     */
    #[Test]
    #[DataProvider('validInputStrings')]
    public function encodingIsDeterministic(string $input): void
    {
        $svg1 = $this->encoder->encode($input);
        $svg2 = $this->encoder->encode($input);

        self::assertSame($svg1, $svg2, 'Encoding the same input must produce identical SVG');
    }

    /**
     * Custom module size and quiet zone do not break SVG structure.
     */
    #[Test]
    public function customModuleSizeAndQuietZoneProduceValidSvg(): void
    {
        $moduleSizes = [1, 2, 4, 8, 16];
        $quietZones = [0, 1, 2, 4, 8];

        foreach ($moduleSizes as $moduleSize) {
            foreach ($quietZones as $quietZone) {
                $svg = $this->encoder->encode('test', $moduleSize, $quietZone);

                self::assertStringStartsWith('<svg ', $svg);
                self::assertStringEndsWith('</svg>', $svg);
                self::assertStringContainsString('viewBox="0 0', $svg);
            }
        }
    }

    /**
     * Data too large for version 10 throws InvalidArgumentException.
     */
    #[Test]
    public function excessiveDataLengthThrows(): void
    {
        // Version 10 byte mode max capacity is very limited;
        // 300 bytes exceeds the available data codewords for EC level M
        $hugeData = str_repeat('X', 300);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Data too large for QR code');

        $this->encoder->encode($hugeData);
    }

    /**
     * SVG width and height attributes are positive integers matching viewBox.
     */
    #[Test]
    #[DataProvider('validInputStrings')]
    public function svgDimensionsAreConsistent(string $input): void
    {
        $svg = $this->encoder->encode($input, 4, 4);

        // Extract width and height from the SVG tag
        self::assertMatchesRegularExpression(
            '/width="\d+"/',
            $svg,
            'SVG must have a numeric width attribute',
        );
        self::assertMatchesRegularExpression(
            '/height="\d+"/',
            $svg,
            'SVG must have a numeric height attribute',
        );

        // Width and height should match (QR codes are square)
        self::assertSame(1, preg_match('/width="(\d+)"/', $svg, $widthMatch));
        self::assertSame(1, preg_match('/height="(\d+)"/', $svg, $heightMatch));
        self::assertTrue(isset($widthMatch[1], $heightMatch[1]));

        self::assertSame(
            $widthMatch[1],
            $heightMatch[1],
            'QR code SVG must be square (width === height)',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validInputStrings(): iterable
    {
        // Empty string
        yield 'empty string' => [''];

        // Single characters
        yield 'single ascii char' => ['A'];
        yield 'single digit' => ['0'];
        yield 'single space' => [' '];

        // Typical use cases
        yield 'url' => ['https://example.com'];
        yield 'email' => ['user@example.com'];
        yield 'phone' => ['+1-555-123-4567'];
        yield 'otpauth uri' => ['otpauth://totp/Example:user@mail.com?secret=JBSWY3DPEHPK3PXP&issuer=Example'];

        // Unicode
        yield 'unicode cjk' => ["\xE6\x97\xA5\xE6\x9C\xAC\xE8\xAA\x9E"];
        yield 'unicode emoji bytes' => ["\xF0\x9F\x98\x80"];

        // Binary-safe boundaries
        yield 'null byte' => ["\x00"];
        yield 'high bytes' => ["\xFF\xFE\xFD"];

        // Random data samples (deterministic seeds for reproducibility)
        yield 'short random' => [substr(md5('seed1'), 0, 8)];
        yield 'medium random' => [md5('seed2')];
        yield 'multi-line' => ["line1\nline2\nline3"];
        yield 'tabs and spaces' => ["\t  \t  \t"];
        yield 'special html chars' => ['<script>alert("xss")</script>'];
        yield 'backslashes' => ['C:\\Users\\test\\path'];
        yield 'max safe length' => [str_repeat('A', 14)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEmptyValidStrings(): iterable
    {
        yield 'ascii text' => ['Hello World'];
        yield 'numeric' => ['1234567890'];
        yield 'url' => ['https://pulsar.dev'];
        yield 'mixed' => ['abc123!@#'];
        yield 'single char' => ['X'];
    }
}
