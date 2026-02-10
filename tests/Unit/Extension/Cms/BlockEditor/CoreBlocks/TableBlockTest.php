<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\TableBlock;

#[CoversClass(TableBlock::class)]
final class TableBlockTest extends TestCase
{
    private TableBlock $block;

    protected function setUp(): void
    {
        $this->block = new TableBlock();
    }

    #[Test]
    public function typeReturnsTable(): void
    {
        self::assertSame('table', $this->block->type());
    }

    #[Test]
    public function rendersTableWithHeaders(): void
    {
        $html = $this->block->render([
            'headers' => ['Name', 'Age'],
            'rows' => [['Alice', '30'], ['Bob', '25']],
            'hasHeaderRow' => true,
        ]);

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<thead>', $html);
        self::assertStringContainsString('<th>Name</th>', $html);
        self::assertStringContainsString('<th>Age</th>', $html);
        self::assertStringContainsString('<td>Alice</td>', $html);
        self::assertStringContainsString('<td>30</td>', $html);
        self::assertStringContainsString('</table>', $html);
    }

    #[Test]
    public function rendersTableWithoutHeaders(): void
    {
        $html = $this->block->render([
            'headers' => ['Name', 'Age'],
            'rows' => [['Alice', '30']],
            'hasHeaderRow' => false,
        ]);

        self::assertStringNotContainsString('<thead>', $html);
        self::assertStringNotContainsString('<th>', $html);
        self::assertStringContainsString('<td>Alice</td>', $html);
    }

    #[Test]
    public function rendersEmptyTable(): void
    {
        $html = $this->block->render([
            'headers' => [],
            'rows' => [],
            'hasHeaderRow' => true,
        ]);

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<tbody>', $html);
        self::assertStringContainsString('</table>', $html);
    }

    #[Test]
    public function escapesXssInCells(): void
    {
        $html = $this->block->render([
            'headers' => ['<script>x</script>'],
            'rows' => [['<img src=x onerror=alert(1)>']],
            'hasHeaderRow' => true,
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function validatesRequiredHeaders(): void
    {
        $errors = $this->block->validate([
            'rows' => [['a']],
            'hasHeaderRow' => true,
        ]);

        self::assertContains('headers is required and must be an array', $errors);
    }

    #[Test]
    public function validatesRequiredRows(): void
    {
        $errors = $this->block->validate([
            'headers' => ['A'],
            'hasHeaderRow' => true,
        ]);

        self::assertContains('rows is required and must be an array', $errors);
    }

    #[Test]
    public function validatesRequiredHasHeaderRow(): void
    {
        $errors = $this->block->validate([
            'headers' => ['A'],
            'rows' => [['a']],
        ]);

        self::assertContains('hasHeaderRow is required and must be a boolean', $errors);
    }

    #[Test]
    public function validatesHeadersMustBeStrings(): void
    {
        $errors = $this->block->validate([
            'headers' => [123],
            'rows' => [['a']],
            'hasHeaderRow' => true,
        ]);

        self::assertContains('headers[0] must be a string', $errors);
    }

    #[Test]
    public function validatesRowsMustBeArrays(): void
    {
        $errors = $this->block->validate([
            'headers' => ['A'],
            'rows' => ['not-an-array'],
            'hasHeaderRow' => true,
        ]);

        self::assertContains('rows[0] must be an array', $errors);
    }

    #[Test]
    public function validatesRowCellsMustBeStrings(): void
    {
        $errors = $this->block->validate([
            'headers' => ['A'],
            'rows' => [[42]],
            'hasHeaderRow' => true,
        ]);

        self::assertContains('rows[0][0] must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'headers' => ['Name'],
            'rows' => [['Alice']],
            'hasHeaderRow' => true,
        ]);

        self::assertSame([], $errors);
    }
}
