<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\VaryHeader;

#[CoversClass(VaryHeader::class)]
final class VaryHeaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function mergeProvider(): iterable
    {
        yield 'into empty' => ['', ['Accept-Language'], 'Accept-Language'];
        yield 'preserves existing token' => ['Cookie', ['Accept-Language'], 'Cookie, Accept-Language'];
        yield 'case-insensitive dedupe (first wins)' => ['accept-language', ['Accept-Language'], 'accept-language'];
        yield 'dedupe across new fields' => ['', ['Accept-Language', 'accept-language'], 'Accept-Language'];
        yield 'comma-separated argument' => ['', ['Accept-Language, Cookie'], 'Accept-Language, Cookie'];
        yield 'trims whitespace and drops empties' => ['  Cookie ,, ', ['  Accept-Language  '], 'Cookie, Accept-Language'];
        yield 'star in existing collapses to star' => ['*', ['Accept-Language'], '*'];
        yield 'star in new collapses to star' => ['Cookie', ['*'], '*'];
        yield 'nothing to add keeps existing' => ['Cookie', [], 'Cookie'];
        yield 'all empty' => ['', [], ''];
    }

    /**
     * @param list<string> $fields
     */
    #[Test]
    #[DataProvider('mergeProvider')]
    public function mergeCombinesTokensWithoutClobbering(string $existing, array $fields, string $expected): void
    {
        self::assertSame($expected, VaryHeader::merge($existing, ...$fields));
    }
}
