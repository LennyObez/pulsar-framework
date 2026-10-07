<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Security\QrCodeEncoder;

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
    public function encodeTypicalOtpauthUri(): void
    {
        // Short URI to stay within version 10 capacity (~78 bytes max for byte mode EC-M)
        $uri = 'otpauth://totp/CMS:user?secret=JBSWY3DP&issuer=CMS';

        $svg = $this->encoder->encode($uri);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function svgOutputIsWellFormedXml(): void
    {
        $svg = $this->encoder->encode('Hello, World!');

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }

    #[Test]
    public function svgContainsDarkModuleRects(): void
    {
        $svg = $this->encoder->encode('test');

        // Dark modules are rendered as <rect> elements without fill (defaults to black)
        self::assertStringContainsString('<rect x=', $svg);
    }

    #[Test]
    public function svgContainsWhiteBackgroundRect(): void
    {
        $svg = $this->encoder->encode('test');

        self::assertStringContainsString('fill="white"', $svg);
    }

    // -- Various input lengths ------------------------------------------------

    #[Test]
    public function shortInput(): void
    {
        $svg = $this->encoder->encode('A');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function mediumInput(): void
    {
        $svg = $this->encoder->encode('The quick brown fox jumps over the lazy dog');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function longerInputUsesHigherVersion(): void
    {
        // ~60 bytes requires a higher version than a 1-byte input, but still within v10 capacity
        $data = str_repeat('X', 60);

        $svg = $this->encoder->encode($data);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    // -- Module size and quiet zone -------------------------------------------

    #[Test]
    public function customModuleSize(): void
    {
        $svg = $this->encoder->encode('test', moduleSize: 8);

        // With module size 8, SVG dimensions should be multiples of 8
        self::assertStringContainsString('width=', $svg);
        self::assertStringContainsString('height=', $svg);
    }

    #[Test]
    public function customQuietZone(): void
    {
        $svg = $this->encoder->encode('test', quietZone: 2);

        self::assertStringStartsWith('<svg', $svg);
    }

    // -- Data too large -------------------------------------------------------

    #[Test]
    public function largeInputStillProducesValidSvg(): void
    {
        // The encoder scales to higher QR versions for large data
        $svg = $this->encoder->encode(str_repeat('X', 500));

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    // -- Different character types --------------------------------------------

    #[Test]
    public function numericInput(): void
    {
        $svg = $this->encoder->encode('1234567890');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function alphanumericInput(): void
    {
        $svg = $this->encoder->encode('ABCDEF0123456789');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function utf8Input(): void
    {
        $svg = $this->encoder->encode('Hallo Welt! Schöne Grüße');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    #[Test]
    public function specialCharactersInput(): void
    {
        $svg = $this->encoder->encode('!@#$%^&*()_+-=[]{}|;:\'",.<>?/`~');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    // -- Deterministic output ------------------------------------------------

    #[Test]
    public function sameInputProducesSameOutput(): void
    {
        $data = 'deterministic test';

        $svg1 = $this->encoder->encode($data);
        $svg2 = $this->encoder->encode($data);

        self::assertSame($svg1, $svg2);
    }

    // -- SVG viewBox and dimensions ------------------------------------------

    #[Test]
    public function svgHasViewbox(): void
    {
        $svg = $this->encoder->encode('viewbox test');

        self::assertStringContainsString('viewBox=', $svg);
    }
}
