<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;

use function str_repeat;

#[CoversClass(QrCodeEncoder::class)]
final class QrCodeEncoderTest extends TestCase
{
    private QrCodeEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new QrCodeEncoder();
    }

    // -- SVG output structure -------------------------------------------------

    #[Test]
    public function test_encode_typical_otpauth_uri(): void
    {
        // Short URI to stay within version 10 capacity (~78 bytes max for byte mode EC-M)
        $uri = 'otpauth://totp/CMS:user?secret=JBSWY3DP&issuer=CMS';

        $svg = $this->encoder->encode($uri);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function test_svg_output_is_well_formed_xml(): void
    {
        $svg = $this->encoder->encode('Hello, World!');

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }

    #[Test]
    public function test_svg_contains_path_element_for_dark_modules(): void
    {
        $svg = $this->encoder->encode('test');

        self::assertStringContainsString('<path', $svg);
        self::assertStringContainsString('fill="#000"', $svg);
    }

    #[Test]
    public function test_svg_contains_white_background_rect(): void
    {
        $svg = $this->encoder->encode('test');

        self::assertStringContainsString('fill="#fff"', $svg);
    }

    // -- Various input lengths ------------------------------------------------

    #[Test]
    public function test_short_input(): void
    {
        $svg = $this->encoder->encode('A');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function test_medium_input(): void
    {
        $svg = $this->encoder->encode('The quick brown fox jumps over the lazy dog');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function test_longer_input_uses_higher_version(): void
    {
        // ~60 bytes requires a higher version than a 1-byte input, but still within v10 capacity
        $data = str_repeat('X', 60);

        $svg = $this->encoder->encode($data);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    // -- Module size and quiet zone -------------------------------------------

    #[Test]
    public function test_custom_module_size(): void
    {
        $svg = $this->encoder->encode('test', moduleSize: 8);

        // With module size 8, SVG dimensions should be multiples of 8
        self::assertStringContainsString('width=', $svg);
        self::assertStringContainsString('height=', $svg);
    }

    #[Test]
    public function test_custom_quiet_zone(): void
    {
        $svg = $this->encoder->encode('test', quietZone: 2);

        self::assertStringStartsWith('<svg', $svg);
    }

    // -- Data too large -------------------------------------------------------

    #[Test]
    public function test_data_too_large_throws_exception(): void
    {
        // Version 10 max capacity for byte mode at EC level M is limited
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data too large');

        $this->encoder->encode(str_repeat('X', 500));
    }

    // -- Different character types --------------------------------------------

    #[Test]
    public function test_numeric_input(): void
    {
        $svg = $this->encoder->encode('1234567890');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function test_alphanumeric_input(): void
    {
        $svg = $this->encoder->encode('ABCDEF0123456789');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function test_utf8_input(): void
    {
        $svg = $this->encoder->encode('Hallo Welt! Schöne Grüße');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function test_special_characters_input(): void
    {
        $svg = $this->encoder->encode('!@#$%^&*()_+-=[]{}|;:\'",.<>?/`~');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    // -- Deterministic output ------------------------------------------------

    #[Test]
    public function test_same_input_produces_same_output(): void
    {
        $data = 'deterministic test';

        $svg1 = $this->encoder->encode($data);
        $svg2 = $this->encoder->encode($data);

        self::assertSame($svg1, $svg2);
    }

    // -- SVG viewBox and dimensions ------------------------------------------

    #[Test]
    public function test_svg_has_viewbox(): void
    {
        $svg = $this->encoder->encode('viewbox test');

        self::assertStringContainsString('viewBox=', $svg);
    }
}
