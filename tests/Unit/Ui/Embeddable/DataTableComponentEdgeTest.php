<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\ColumnDefinition;
use Pulsar\Ui\Embeddable\DataTableComponent;
use Pulsar\Ui\Embeddable\EmbeddableComponent;

/**
 * Edge case tests for DataTableComponent.
 */
#[CoversClass(DataTableComponent::class)]
#[CoversClass(EmbeddableComponent::class)]
#[CoversClass(ColumnDefinition::class)]
final class DataTableComponentEdgeTest extends TestCase
{
    #[Test]
    public function tagNameReturnsPulsarDataTable(): void
    {
        $table = new DataTableComponent();

        self::assertSame('pulsar-data-table', $table->tagName());
    }

    #[Test]
    public function renderWithExplicitColumns(): void
    {
        $table = new DataTableComponent();
        $table->columns([
            new ColumnDefinition('name', 'Name'),
            new ColumnDefinition('email', 'Email'),
        ]);
        $table->rows([
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        ]);

        $html = $table->render();

        self::assertStringContainsString('<th', $html);
        self::assertStringContainsString('Name', $html);
        self::assertStringContainsString('Email', $html);
        self::assertStringContainsString('Alice', $html);
        self::assertStringContainsString('alice@example.com', $html);
    }

    #[Test]
    public function renderAutoDetectsColumnsFromRows(): void
    {
        $table = new DataTableComponent();
        $table->rows([
            ['id' => 1, 'title' => 'Post 1'],
        ]);

        $html = $table->render();

        // Auto-detected columns should have ucfirst labels
        self::assertStringContainsString('Id', $html);
        self::assertStringContainsString('Title', $html);
        self::assertStringContainsString('Post 1', $html);
    }

    #[Test]
    public function renderEmptyTableHasHeaderButNoRows(): void
    {
        $table = new DataTableComponent();
        $table->columns([
            new ColumnDefinition('col', 'Column'),
        ]);

        $html = $table->render();

        self::assertStringContainsString('Column', $html);
        self::assertStringContainsString('<tbody>', $html);
    }

    #[Test]
    public function sortableAddsAriaSort(): void
    {
        $table = new DataTableComponent();
        $table->sortable(true);
        $table->columns([new ColumnDefinition('x', 'X')]);
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('aria-sort="none"', $html);
    }

    #[Test]
    public function notSortableOmitsAriaSort(): void
    {
        $table = new DataTableComponent();
        $table->sortable(false);
        $table->columns([new ColumnDefinition('x', 'X')]);
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringNotContainsString('aria-sort', $html);
    }

    #[Test]
    public function filterableAddsSearchInput(): void
    {
        $table = new DataTableComponent();
        $table->filterable(true);
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('type="search"', $html);
        self::assertStringContainsString('pulsar-dt-filter', $html);
    }

    #[Test]
    public function notFilterableOmitsSearchInput(): void
    {
        $table = new DataTableComponent();
        $table->filterable(false);
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringNotContainsString('type="search"', $html);
    }

    #[Test]
    public function pageSizeSetsProp(): void
    {
        $table = new DataTableComponent();
        $table->pageSize(50);
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('data-props=', $html);
        self::assertStringContainsString('50', $html);
    }

    #[Test]
    public function renderEscapesHtmlInCellValues(): void
    {
        $table = new DataTableComponent();
        $table->columns([new ColumnDefinition('text', 'Text')]);
        $table->rows([['text' => '<script>alert(1)</script>']]);

        $html = $table->render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderHandlesNullCellValues(): void
    {
        $table = new DataTableComponent();
        $table->columns([new ColumnDefinition('val', 'Val')]);
        $table->rows([['val' => null]]);

        $html = $table->render();

        self::assertStringContainsString('<td></td>', $html);
    }

    #[Test]
    public function renderWrapsInCustomElement(): void
    {
        $table = new DataTableComponent();
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringStartsWith('<pulsar-data-table', $html);
        self::assertStringEndsWith('</pulsar-data-table>', $html);
    }

    #[Test]
    public function attrSetsCustomAttribute(): void
    {
        $table = new DataTableComponent();
        $table->attr('id', 'my-table');
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('id="my-table"', $html);
    }

    #[Test]
    public function columnDefinitionProperties(): void
    {
        $col = new ColumnDefinition('user_name', 'User Name', sortable: false, filterable: true, format: 'uppercase');

        self::assertSame('user_name', $col->key);
        self::assertSame('User Name', $col->label);
        self::assertFalse($col->sortable);
        self::assertTrue($col->filterable);
        self::assertSame('uppercase', $col->format);
    }

    #[Test]
    public function columnDefinitionDefaults(): void
    {
        $col = new ColumnDefinition('id', 'ID');

        self::assertTrue($col->sortable);
        self::assertTrue($col->filterable);
        self::assertNull($col->format);
    }
}
