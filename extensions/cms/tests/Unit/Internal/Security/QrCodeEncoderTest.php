<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Security\QrCodeEncoder;

final class QrCodeEncoderTest extends TestCase
{
    private QrCodeEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new QrCodeEncoder();
    }

    #[Test]
    public function encode_returns_valid_svg(): void
    {
        $svg = $this->encoder->encode('https://example.com');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }

    #[Test]
    public function encode_contains_accessibility_attributes(): void
    {
        $svg = $this->encoder->encode('test');

        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('aria-label="QR Code:', $svg);
        self::assertStringContainsString('<title>QR Code</title>', $svg);
    }

    #[Test]
    public function encode_contains_rect_elements_for_dark_modules(): void
    {
        $svg = $this->encoder->encode('test');

        self::assertStringContainsString('<rect', $svg);
    }

    #[Test]
    public function encode_escapes_html_in_data(): void
    {
        $svg = $this->encoder->encode('<script>alert("xss")</script>');

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    #[Test]
    public function encode_uses_default_module_size(): void
    {
        $svg = $this->encoder->encode('test', moduleSize: 4);

        self::assertStringContainsString('width="4"', $svg);
        self::assertStringContainsString('height="4"', $svg);
    }

    #[Test]
    public function encode_produces_different_output_for_different_data(): void
    {
        $svg1 = $this->encoder->encode('hello');
        $svg2 = $this->encoder->encode('world');

        self::assertNotSame($svg1, $svg2);
    }

    #[Test]
    public function encode_handles_empty_string(): void
    {
        $svg = $this->encoder->encode('');

        self::assertStringStartsWith('<svg', $svg);
    }

    #[Test]
    public function encode_handles_longer_data(): void
    {
        $svg = $this->encoder->encode('otpauth://totp/MyApp:user@example.com?secret=JBSWY3DPEHPK3PXP&issuer=MyApp');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }
}
