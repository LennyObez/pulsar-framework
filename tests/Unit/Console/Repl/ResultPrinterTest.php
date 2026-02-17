<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\ResultPrinter;
use Pulsar\Console\Repl\SyntaxHighlighter;
use stdClass;

use function strlen;

#[CoversClass(ResultPrinter::class)]
final class ResultPrinterTest extends TestCase
{
    private ResultPrinter $printer;

    protected function setUp(): void
    {
        $this->printer = new ResultPrinter();
    }

    #[Test]
    public function formatNullShowsCyanNull(): void
    {
        $result = $this->printer->format(null);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertSame('null', $stripped);
    }

    #[Test]
    public function formatTrueShowsYellowTrue(): void
    {
        $result = $this->printer->format(true);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertSame('true', $stripped);
    }

    #[Test]
    public function formatFalseShowsYellowFalse(): void
    {
        $result = $this->printer->format(false);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertSame('false', $stripped);
    }

    #[Test]
    public function formatIntegerShowsCyanValue(): void
    {
        $result = $this->printer->format(42);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertSame('42', $stripped);
    }

    #[Test]
    public function formatFloatShowsFormattedValue(): void
    {
        $result = $this->printer->format(3.14);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertSame('3.14', $stripped);
    }

    #[Test]
    public function formatShortStringShowsFullContent(): void
    {
        $result = $this->printer->format('hello');

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('string(5)', $stripped);
        self::assertStringContainsString('"hello"', $stripped);
    }

    #[Test]
    public function formatLongStringTruncates(): void
    {
        $long = str_repeat('x', 300);
        $result = $this->printer->format($long);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('string(300)', $stripped);
        self::assertStringContainsString('(truncated)', $stripped);
    }

    #[Test]
    public function formatEmptyArrayShowsBrackets(): void
    {
        $result = $this->printer->format([]);

        self::assertSame('[]', $result);
    }

    #[Test]
    public function formatSmallArrayShowsAllItems(): void
    {
        $result = $this->printer->format(['a' => 1, 'b' => 2]);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('array(2)', $stripped);
        self::assertStringContainsString('"a" => 1', $stripped);
        self::assertStringContainsString('"b" => 2', $stripped);
    }

    #[Test]
    public function formatLargeArrayTruncatesWithMessage(): void
    {
        $large = array_fill(0, 25, 'value');
        $result = $this->printer->format($large);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('array(25)', $stripped);
        self::assertStringContainsString('more items', $stripped);
    }

    #[Test]
    public function formatNestedArrayIndents(): void
    {
        $result = $this->printer->format(['nested' => ['deep' => 1]]);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('"nested"', $stripped);
        self::assertStringContainsString('"deep"', $stripped);
    }

    #[Test]
    public function formatObjectShowsClassAndProperties(): void
    {
        $obj = new class ('test', 42) {
            public function __construct(
                public string $name,
                public int $count,
            ) {}
        };

        $result = $this->printer->format($obj);
        $stripped = SyntaxHighlighter::stripAnsi($result);

        self::assertStringContainsString('name', $stripped);
        self::assertStringContainsString('test', $stripped);
        self::assertStringContainsString('count', $stripped);
        self::assertStringContainsString('42', $stripped);
    }

    #[Test]
    public function formatObjectWithNoPropertiesShowsEmptyBraces(): void
    {
        $obj = new class {};

        $result = $this->printer->format($obj);
        $stripped = SyntaxHighlighter::stripAnsi($result);

        self::assertStringContainsString('{}', $stripped);
    }

    #[Test]
    public function formatBackedEnumShowsNameAndValue(): void
    {
        $result = $this->printer->format(TestBackedEnum::Active);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('Active', $stripped);
        self::assertStringContainsString('active', $stripped);
    }

    #[Test]
    public function formatUnitEnumShowsName(): void
    {
        $result = $this->printer->format(TestUnitEnum::Red);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('Red', $stripped);
    }

    #[Test]
    public function formatMaxDepthStopsRecursion(): void
    {
        // Create deeply nested array
        $deep = [[[[[[[[[['bottom']]]]]]]]]];

        $result = $this->printer->format($deep);

        self::assertStringContainsString('max depth', $result);
    }

    #[Test]
    public function summaryNull(): void
    {
        self::assertSame('null', $this->printer->summary(null));
    }

    #[Test]
    public function summaryBool(): void
    {
        self::assertSame('true', $this->printer->summary(true));
        self::assertSame('false', $this->printer->summary(false));
    }

    #[Test]
    public function summaryNumber(): void
    {
        self::assertSame('42', $this->printer->summary(42));
        self::assertSame('3.14', $this->printer->summary(3.14));
    }

    #[Test]
    public function summaryShortString(): void
    {
        self::assertSame('"hello"', $this->printer->summary('hello'));
    }

    #[Test]
    public function summaryLongStringTruncates(): void
    {
        $long = str_repeat('x', 100);
        $result = $this->printer->summary($long);

        self::assertStringContainsString('...', $result);
        self::assertLessThan(60, strlen($result));
    }

    #[Test]
    public function summaryArrayShowsCount(): void
    {
        self::assertSame('array(3)', $this->printer->summary([1, 2, 3]));
    }

    #[Test]
    public function summaryObjectShowsClass(): void
    {
        $obj = new stdClass();
        $result = $this->printer->summary($obj);

        self::assertStringContainsString('stdClass', $result);
    }

    #[Test]
    public function formatTableWithEmptyRowsShowsMessage(): void
    {
        $result = $this->printer->formatTable([], ['id', 'name']);

        self::assertSame('(empty result set)', $result);
    }

    #[Test]
    public function formatTableRendersHeadersAndData(): void
    {
        $rows = [
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ];

        $result = $this->printer->formatTable($rows, ['id', 'name']);

        self::assertStringContainsString('id', $result);
        self::assertStringContainsString('name', $result);
        self::assertStringContainsString('Alice', $result);
        self::assertStringContainsString('Bob', $result);
        self::assertStringContainsString('+', $result);
        self::assertStringContainsString('|', $result);
    }

    #[Test]
    public function formatTableAlignsByLongestValue(): void
    {
        $rows = [
            ['col' => 'short'],
            ['col' => 'a much longer value here'],
        ];

        $result = $this->printer->formatTable($rows, ['col']);

        // Both rows should have the same width separators
        $lines = explode("\n", $result);
        $separators = array_filter($lines, static fn(string $l): bool => str_starts_with($l, '+'));
        $lengths = array_map(static fn(string $l): int => strlen($l), $separators);

        // All separator lines should have the same length
        self::assertCount(1, array_unique($lengths));
    }

    #[Test]
    public function formatTableHandlesMissingColumns(): void
    {
        $rows = [
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2'], // missing 'name'
        ];

        $result = $this->printer->formatTable($rows, ['id', 'name']);

        // Should not throw, should show empty for missing column
        self::assertStringContainsString('Alice', $result);
    }
}

enum TestBackedEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum TestUnitEnum
{
    case Red;
    case Blue;
}
