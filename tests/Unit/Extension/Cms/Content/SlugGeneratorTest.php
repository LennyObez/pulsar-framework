<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\SlugGenerator;

use function strlen;

#[CoversClass(SlugGenerator::class)]
final class SlugGeneratorTest extends TestCase
{
    private SlugGenerator $generator;

    /** @var ConnectionInterface&\PHPUnit\Framework\MockObject\Stub */
    private ConnectionInterface $db;

    protected function setUp(): void
    {
        $this->db = $this->createStub(ConnectionInterface::class);
        $this->generator = new SlugGenerator();
    }

    // ── generate() ───────────────────────────────────────────────────

    #[Test]
    #[DataProvider('generateProvider')]
    public function test_generate_produces_expected_slug(string $title, string $expected): void
    {
        self::assertSame($expected, $this->generator->generate($title));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function generateProvider(): iterable
    {
        yield 'simple title' => ['Hello World', 'hello-world'];
        yield 'accented chars' => ["Caf\u{00e9} R\u{00e9}sum\u{00e9}", 'cafe-resume'];
        yield 'multiple hyphens' => ['Multiple---Hyphens', 'multiple-hyphens'];
        yield 'special characters' => ['Hello! @World# $2024', 'hello-world-2024'];
        yield 'leading trailing spaces' => ['  Spaced Title  ', 'spaced-title'];
        yield 'numeric only' => ['12345', '12345'];
        yield 'mixed case' => ['CamelCaseTitle', 'camelcasetitle'];
        yield 'underscores' => ['underscore_title', 'underscore-title'];
        yield 'dots and commas' => ['Hello. World, Yes', 'hello-world-yes'];
        yield 'unicode German' => ["\u{00dc}ber Stra\u{00df}e", 'uber-strasse'];
        yield 'parentheses' => ['Title (v2)', 'title-v2'];
        yield 'ampersand' => ['Salt & Pepper', 'salt-pepper'];
    }

    #[Test]
    public function test_generate_truncates_at_200_chars(): void
    {
        $longTitle = str_repeat('a', 300);
        $slug = $this->generator->generate($longTitle);
        self::assertLessThanOrEqual(200, strlen($slug));
    }

    #[Test]
    public function test_generate_truncation_does_not_end_with_hyphen(): void
    {
        // Create a title that will produce a slug with a hyphen near the 200-char boundary
        $title = str_repeat('word ', 60); // ~300 chars, will become "word-word-word..."
        $slug = $this->generator->generate($title);
        self::assertLessThanOrEqual(200, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    #[Test]
    public function test_generate_empty_title_returns_empty(): void
    {
        self::assertSame('', $this->generator->generate(''));
    }

    // ── validate() ───────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validSlugProvider')]
    public function test_validate_accepts_valid_slugs(string $slug): void
    {
        self::assertTrue($this->generator->validate($slug));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validSlugProvider(): iterable
    {
        yield 'simple' => ['hello-world'];
        yield 'single word' => ['hello'];
        yield 'numeric' => ['123'];
        yield 'alpha numeric mix' => ['article-2024-01'];
        yield 'single char' => ['a'];
    }

    #[Test]
    #[DataProvider('invalidSlugProvider')]
    public function test_validate_rejects_invalid_slugs(string $slug): void
    {
        self::assertFalse($this->generator->validate($slug));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'uppercase' => ['Hello-World'];
        yield 'starts with hyphen' => ['-hello'];
        yield 'ends with hyphen' => ['hello-'];
        yield 'consecutive hyphens' => ['hello--world'];
        yield 'spaces' => ['hello world'];
        yield 'special chars' => ['hello!world'];
        yield 'slashes' => ['hello/world'];
        yield 'empty string' => [''];
        yield 'over 200 chars' => [str_repeat('a', 201)];
    }

    // ── ensureUnique() ───────────────────────────────────────────────

    #[Test]
    public function test_ensure_unique_returns_slug_when_no_conflict(): void
    {
        $emptyResult = new Result([]);

        $this->db->method('query')->willReturn($emptyResult);

        $result = $this->generator->ensureUnique('hello-world', 'en', null, null, $this->db);
        self::assertSame('hello-world', $result);
    }

    #[Test]
    public function test_ensure_unique_appends_suffix_on_conflict(): void
    {
        $existsResult = new Result([new Row(['exists' => 1])]);
        $emptyResult = new Result([]);

        $this->db->method('query')
            ->willReturnOnConsecutiveCalls($existsResult, $emptyResult);

        $result = $this->generator->ensureUnique('hello-world', 'en', null, null, $this->db);
        self::assertSame('hello-world-2', $result);
    }

    #[Test]
    public function test_ensure_unique_increments_suffix_through_conflicts(): void
    {
        $existsResult = new Result([new Row(['exists' => 1])]);
        $emptyResult = new Result([]);

        // First three exist, fourth is free
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls($existsResult, $existsResult, $existsResult, $emptyResult);

        $result = $this->generator->ensureUnique('hello-world', 'en', null, null, $this->db);
        self::assertSame('hello-world-4', $result);
    }
}
