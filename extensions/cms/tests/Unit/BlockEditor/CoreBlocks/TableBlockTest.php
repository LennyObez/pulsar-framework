<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderOutputsTableWithHeaders(): void
    {
        $html = $this->block->render([
            'headers' => ['Name', 'Age'],
            'rows' => [['Alice', '30'], ['Bob', '25']],
            'hasHeaderRow' => true,
        ]);

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<thead>', $html);
        self::assertStringContainsString('<th>Name</th>', $html);
        self::assertStringContainsString('<td>Alice</td>', $html);
        self::assertStringContainsString('<td>25</td>', $html);
    }

    #[Test]
    public function renderOmitsTheadWhenHeaderRowDisabled(): void
    {
        $html = $this->block->render([
            'headers' => ['Name', 'Age'],
            'rows' => [['Alice', '30']],
            'hasHeaderRow' => false,
        ]);

        self::assertStringNotContainsString('<thead>', $html);
    }

    #[Test]
    public function renderEscapesCellContent(): void
    {
        $html = $this->block->render([
            'headers' => ['Data'],
            'rows' => [['<script>alert(1)</script>']],
            'hasHeaderRow' => true,
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderSkipsNonArrayRows(): void
    {
        $html = $this->block->render([
            'headers' => ['A'],
            'rows' => ['not-array', 42],
            'hasHeaderRow' => false,
        ]);

        self::assertStringNotContainsString('<td>', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingHeaders(): void
    {
        $errors = $this->block->validate([
            'rows' => [['a']],
            'hasHeaderRow' => true,
        ]);

        self::assertStringContainsString('headers is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingRows(): void
    {
        $errors = $this->block->validate([
            'headers' => ['A'],
            'hasHeaderRow' => true,
        ]);

        self::assertStringContainsString('rows is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingHasHeaderRow(): void
    {
        $errors = $this->block->validate([
            'headers' => ['A'],
            'rows' => [['a']],
        ]);

        self::assertStringContainsString('hasHeaderRow is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'headers' => ['Col1'],
            'rows' => [['val1']],
            'hasHeaderRow' => true,
        ]));
    }
}
