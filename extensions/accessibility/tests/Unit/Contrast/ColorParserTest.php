<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Contrast;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\ColorParser;
use Pulsar\Extension\Accessibility\Contrast\InvalidColorException;

final class ColorParserTest extends TestCase
{
    private ColorParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ColorParser();
    }

    #[Test]
    public function parse_hex_black(): void
    {
        $color = $this->parser->parse('#000000');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
        self::assertSame(1.0, $color->alpha);
    }

    #[Test]
    public function parse_hex_shorthand_white(): void
    {
        $color = $this->parser->parse('#fff');

        self::assertSame(255, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(255, $color->b);
    }

    #[Test]
    public function parse_hex_red(): void
    {
        $color = $this->parser->parse('#FF0000');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function parse_rgb_comma_syntax(): void
    {
        $color = $this->parser->parse('rgb(0, 0, 0)');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function parse_rgb_space_syntax(): void
    {
        $color = $this->parser->parse('rgb(255 255 255)');

        self::assertSame(255, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(255, $color->b);
    }

    #[Test]
    public function parse_hsl_black(): void
    {
        $color = $this->parser->parse('hsl(0, 0%, 0%)');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function parse_hsl_red(): void
    {
        $color = $this->parser->parse('hsl(0, 100%, 50%)');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function parse_named_color_black(): void
    {
        $color = $this->parser->parse('black');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function parse_named_color_white(): void
    {
        $color = $this->parser->parse('white');

        self::assertSame(255, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(255, $color->b);
    }

    #[Test]
    public function parse_named_color_red(): void
    {
        $color = $this->parser->parse('red');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function invalid_color_throws_exception(): void
    {
        $this->expectException(InvalidColorException::class);

        $this->parser->parse('not-a-color');
    }

    #[Test]
    public function empty_string_throws_exception(): void
    {
        $this->expectException(InvalidColorException::class);

        $this->parser->parse('');
    }

    #[Test]
    public function parse_hex_with_alpha(): void
    {
        $color = $this->parser->parse('#FF000080');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
        self::assertEqualsWithDelta(0.5, $color->alpha, 0.02);
    }

    #[Test]
    public function parse_rgba_function(): void
    {
        $color = $this->parser->parse('rgba(128, 64, 32, 0.5)');

        self::assertSame(128, $color->r);
        self::assertSame(64, $color->g);
        self::assertSame(32, $color->b);
        self::assertSame(0.5, $color->alpha);
    }

    #[Test]
    public function named_colors_are_case_insensitive(): void
    {
        $color = $this->parser->parse('BLACK');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function invalid_hex_length_throws_exception(): void
    {
        $this->expectException(InvalidColorException::class);

        $this->parser->parse('#12345');
    }
}
