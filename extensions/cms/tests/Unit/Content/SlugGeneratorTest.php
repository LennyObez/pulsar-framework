<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\SlugGenerator;

use function strlen;

final class SlugGeneratorTest extends TestCase
{
    private SlugGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SlugGenerator();
    }

    #[Test]
    public function generates_slug_from_simple_title(): void
    {
        self::assertSame('hello-world', $this->generator->generate('Hello World'));
    }

    #[Test]
    public function generates_slug_with_special_characters(): void
    {
        self::assertSame('hello-world', $this->generator->generate('Hello & World!'));
    }

    #[Test]
    public function collapses_consecutive_hyphens(): void
    {
        self::assertSame('a-b', $this->generator->generate('a --- b'));
    }

    #[Test]
    public function trims_hyphens_from_ends(): void
    {
        self::assertSame('hello', $this->generator->generate('---hello---'));
    }

    #[Test]
    public function truncates_to_max_length(): void
    {
        $longTitle = str_repeat('a', 300);

        $slug = $this->generator->generate($longTitle);

        self::assertLessThanOrEqual(200, strlen($slug));
    }

    #[Test]
    public function handles_empty_string(): void
    {
        self::assertSame('', $this->generator->generate(''));
    }

    #[Test]
    public function lowercases_all_characters(): void
    {
        self::assertSame('abc-def', $this->generator->generate('ABC DEF'));
    }

    #[Test]
    public function validates_correct_slugs(): void
    {
        self::assertTrue($this->generator->validate('hello-world'));
        self::assertTrue($this->generator->validate('a'));
        self::assertTrue($this->generator->validate('abc123'));
    }

    #[Test]
    public function rejects_invalid_slugs(): void
    {
        self::assertFalse($this->generator->validate('-leading'));
        self::assertFalse($this->generator->validate('trailing-'));
        self::assertFalse($this->generator->validate('double--hyphen'));
        self::assertFalse($this->generator->validate('UPPERCASE'));
        self::assertFalse($this->generator->validate(''));
    }
}
