<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Str;

final class StrTest extends TestCase
{
    // ── slug ──────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('slugProvider')]
    public function slugConvertsStrings(string $input, string $expected): void
    {
        self::assertSame($expected, Str::slug($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugProvider(): iterable
    {
        yield 'simple' => ['Hello World', 'hello-world'];
        yield 'special chars' => ['Hello! World?', 'hello-world'];
        yield 'multiple spaces' => ['hello   world', 'hello-world'];
        yield 'already slug' => ['hello-world', 'hello-world'];
        yield 'camelCase' => ['helloWorld', 'helloworld'];
        yield 'empty' => ['', ''];
        yield 'numbers' => ['PHP 8.5', 'php-8-5'];
    }

    #[Test]
    public function slugWithCustomSeparator(): void
    {
        self::assertSame('hello_world', Str::slug('Hello World', '_'));
    }

    // ── camel ─────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('camelProvider')]
    public function camelConvertsStrings(string $input, string $expected): void
    {
        self::assertSame($expected, Str::camel($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function camelProvider(): iterable
    {
        yield 'snake_case' => ['hello_world', 'helloWorld'];
        yield 'kebab-case' => ['hello-world', 'helloWorld'];
        yield 'space separated' => ['hello world', 'helloWorld'];
        yield 'single word' => ['hello', 'hello'];
        yield 'already camel' => ['helloWorld', 'helloworld'];
    }

    // ── snake ─────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('snakeProvider')]
    public function snakeConvertsStrings(string $input, string $expected): void
    {
        self::assertSame($expected, Str::snake($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function snakeProvider(): iterable
    {
        yield 'camelCase' => ['helloWorld', 'hello_world'];
        yield 'PascalCase' => ['HelloWorld', 'hello_world'];
        yield 'kebab-case' => ['hello-world', 'hello_world'];
        yield 'space separated' => ['hello world', 'hello_world'];
        yield 'already snake' => ['hello_world', 'hello_world'];
        yield 'acronym' => ['HTMLParser', 'html_parser'];
    }

    // ── studly ────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('studlyProvider')]
    public function studlyConvertsStrings(string $input, string $expected): void
    {
        self::assertSame($expected, Str::studly($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function studlyProvider(): iterable
    {
        yield 'snake_case' => ['hello_world', 'HelloWorld'];
        yield 'kebab-case' => ['hello-world', 'HelloWorld'];
        yield 'space separated' => ['hello world', 'HelloWorld'];
        yield 'single word' => ['hello', 'Hello'];
    }

    // ── contains / startsWith / endsWith ──────────────────────────────

    #[Test]
    public function containsChecksSubstring(): void
    {
        self::assertTrue(Str::contains('hello world', 'world'));
        self::assertFalse(Str::contains('hello world', 'xyz'));
        self::assertTrue(Str::contains('hello', ''));
    }

    #[Test]
    public function startsWithChecksPrefix(): void
    {
        self::assertTrue(Str::startsWith('hello world', 'hello'));
        self::assertFalse(Str::startsWith('hello world', 'world'));
        self::assertTrue(Str::startsWith('hello', ''));
    }

    #[Test]
    public function endsWithChecksSuffix(): void
    {
        self::assertTrue(Str::endsWith('hello world', 'world'));
        self::assertFalse(Str::endsWith('hello world', 'hello'));
        self::assertTrue(Str::endsWith('hello', ''));
    }

    // ── before / after / afterLast ────────────────────────────────────

    #[Test]
    public function beforeReturnsSubstringBeforeDelimiter(): void
    {
        self::assertSame('hello', Str::before('hello@world.com', '@'));
        self::assertSame('no-at-sign', Str::before('no-at-sign', '@'));
        self::assertSame('hello', Str::before('hello', ''));
    }

    #[Test]
    public function afterReturnsSubstringAfterDelimiter(): void
    {
        self::assertSame('world.com', Str::after('hello@world.com', '@'));
        self::assertSame('no-at-sign', Str::after('no-at-sign', '@'));
        self::assertSame('hello', Str::after('hello', ''));
    }

    #[Test]
    public function afterLastReturnsSubstringAfterLastDelimiter(): void
    {
        self::assertSame('baz', Str::afterLast('foo/bar/baz', '/'));
        self::assertSame('no-slash', Str::afterLast('no-slash', '/'));
    }

    // ── limit ─────────────────────────────────────────────────────────

    #[Test]
    public function limitTruncatesLongStrings(): void
    {
        self::assertSame('Hello...', Str::limit('Hello World', 5));
        self::assertSame('Short', Str::limit('Short', 10));
        self::assertSame('AB~~', Str::limit('ABCDEF', 2, '~~'));
    }

    // ── uuid ──────────────────────────────────────────────────────────

    #[Test]
    public function uuidGeneratesValidFormat(): void
    {
        $uuid = Str::uuid();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    #[Test]
    public function uuidGeneratesUniqueValues(): void
    {
        $uuid1 = Str::uuid();
        $uuid2 = Str::uuid();

        self::assertNotSame($uuid1, $uuid2);
    }

    // ── is ─────────────────────────────────────────────────────────────

    #[Test]
    public function isMatchesPatternWithWildcard(): void
    {
        self::assertTrue(Str::is('foo*', 'foobar'));
        self::assertTrue(Str::is('*bar', 'foobar'));
        self::assertTrue(Str::is('foo*bar', 'fooXbar'));
        self::assertFalse(Str::is('foo*', 'barfoo'));
        self::assertTrue(Str::is('exact', 'exact'));
    }

    // ── kebab ─────────────────────────────────────────────────────────

    #[Test]
    public function kebabConvertsToKebabCase(): void
    {
        self::assertSame('hello-world', Str::kebab('helloWorld'));
        self::assertSame('hello-world', Str::kebab('HelloWorld'));
    }
}
