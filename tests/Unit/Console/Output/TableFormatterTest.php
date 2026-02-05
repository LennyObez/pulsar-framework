<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\Output\TableFormatter;

#[CoversClass(TableFormatter::class)]
final class TableFormatterTest extends TestCase
{
    #[Test]
    public function rendersTableWithHeadersAndRows(): void
    {
        $output = new BufferedOutput();
        $table = new TableFormatter();

        $table->setHeaders(['Name', 'Age', 'City']);
        $table->addRow(['Alice', '30', 'NYC']);
        $table->addRow(['Bob', '25', 'LA']);
        $table->render($output);

        $buffer = $output->buffer;

        self::assertStringContainsString('Name', $buffer);
        self::assertStringContainsString('Alice', $buffer);
        self::assertStringContainsString('Bob', $buffer);
        self::assertStringContainsString('30', $buffer);
        // Box-drawing characters
        self::assertStringContainsString('┌', $buffer);
        self::assertStringContainsString('┘', $buffer);
        self::assertStringContainsString('│', $buffer);
    }

    #[Test]
    public function rendersWithSetRows(): void
    {
        $output = new BufferedOutput();
        $table = new TableFormatter();

        $table->setHeaders(['Col1']);
        $table->setRows([['A'], ['B'], ['C']]);
        $table->render($output);

        $buffer = $output->buffer;
        self::assertStringContainsString('A', $buffer);
        self::assertStringContainsString('C', $buffer);
    }

    #[Test]
    public function rendersEmptyTableProducesNoOutput(): void
    {
        $output = new BufferedOutput();
        $table = new TableFormatter();
        $table->render($output);

        self::assertSame('', $output->buffer);
    }

    #[Test]
    public function rendersWithRowsOnly(): void
    {
        $output = new BufferedOutput();
        $table = new TableFormatter();

        $table->addRow(['data1', 'data2']);
        $table->render($output);

        $buffer = $output->buffer;
        self::assertStringContainsString('data1', $buffer);
        self::assertStringContainsString('data2', $buffer);
        // No header separator
        self::assertStringNotContainsString('├', $buffer);
    }

    #[Test]
    public function resetClearsData(): void
    {
        $output = new BufferedOutput();
        $table = new TableFormatter();

        $table->setHeaders(['H1']);
        $table->addRow(['R1']);
        $table->reset();
        $table->render($output);

        self::assertSame('', $output->buffer);
    }

    #[Test]
    public function columnWidthCalculation(): void
    {
        $output = new BufferedOutput();
        $table = new TableFormatter();

        $table->setHeaders(['Short', 'LongerHeader']);
        $table->addRow(['VeryLongValue', 'S']);
        $table->render($output);

        $buffer = $output->buffer;
        // The VeryLongValue column should be wide enough
        self::assertStringContainsString('VeryLongValue', $buffer);
        self::assertStringContainsString('LongerHeader', $buffer);
    }

    #[Test]
    public function fluentInterface(): void
    {
        $table = new TableFormatter();

        $result = $table->setHeaders(['H1']);
        self::assertSame($table, $result);

        $result = $table->addRow(['R1']);
        self::assertSame($table, $result);

        $result = $table->setRows([['R2']]);
        self::assertSame($table, $result);

        $result = $table->reset();
        self::assertSame($table, $result);
    }
}
