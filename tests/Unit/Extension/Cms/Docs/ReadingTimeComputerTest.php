<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Docs\ReadingTimeComputer;

#[CoversClass(ReadingTimeComputer::class)]
final class ReadingTimeComputerTest extends TestCase
{
    /**
     * @param int $expectedMinutes
     */
    #[Test]
    #[DataProvider('provideReadingTimeScenarios')]
    public function computeReturnsCorrectMinutes(string $text, int $expectedMinutes): void
    {
        self::assertSame($expectedMinutes, ReadingTimeComputer::compute($text));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideReadingTimeScenarios(): iterable
    {
        yield 'exactly 200 words is 1 minute' => [
            implode(' ', array_fill(0, 200, 'word')),
            1,
        ];

        yield '400 words is 2 minutes' => [
            implode(' ', array_fill(0, 400, 'word')),
            2,
        ];

        yield '201 words rounds up to 2 minutes' => [
            implode(' ', array_fill(0, 201, 'word')),
            2,
        ];

        yield 'empty string returns 1 minute floor' => [
            '',
            1,
        ];

        yield 'single word returns 1 minute' => [
            'hello',
            1,
        ];

        yield '1000 words is 5 minutes' => [
            implode(' ', array_fill(0, 1000, 'word')),
            5,
        ];
    }

    #[Test]
    public function computeStripsHtmlTagsBeforeCounting(): void
    {
        // 200 words wrapped in HTML tags should still be 1 minute
        $words = implode(' ', array_fill(0, 200, 'word'));
        $html = "<div><p>{$words}</p></div>";

        self::assertSame(1, ReadingTimeComputer::compute($html));
    }

    #[Test]
    public function computeHandlesNestedHtmlWithAttributes(): void
    {
        // 10 words inside nested HTML
        $html = '<div class="content"><h1>Title Here</h1><p>One two three four five six seven eight</p></div>';
        // "Title Here One two three four five six seven eight" = 10 words → ceil(10/200) = 1
        self::assertSame(1, ReadingTimeComputer::compute($html));
    }
}
