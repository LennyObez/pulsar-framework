<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\ColumnDefinition;
use Pulsar\Ui\Embeddable\DataTableComponent;

#[CoversClass(DataTableComponent::class)]
final class DataTableComponentTest extends TestCase
{
    #[Test]
    public function renders_custom_element(): void
    {
        $table = new DataTableComponent();
        $table->rows([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ]);

        $html = $table->render();

        self::assertStringStartsWith('<pulsar-data-table', $html);
        self::assertStringEndsWith('</pulsar-data-table>', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('Alice', $html);
        self::assertStringContainsString('Bob', $html);
    }

    #[Test]
    public function auto_detects_columns_from_rows(): void
    {
        $table = new DataTableComponent();
        $table->rows([
            ['email' => 'a@b.com', 'name' => 'Test'],
        ]);

        $html = $table->render();

        self::assertStringContainsString('<th', $html);
        self::assertStringContainsString('Email', $html);
        self::assertStringContainsString('Name', $html);
    }

    #[Test]
    public function explicit_columns(): void
    {
        $table = new DataTableComponent();
        $table->columns([
            new ColumnDefinition('id', 'ID'),
            new ColumnDefinition('name', 'Full Name'),
        ]);
        $table->rows([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $html = $table->render();

        self::assertStringContainsString('Full Name', $html);
        self::assertStringContainsString('ID', $html);
    }

    #[Test]
    public function filterable_shows_search_input(): void
    {
        $table = new DataTableComponent();
        $table->filterable()->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('type="search"', $html);
        self::assertStringContainsString('aria-label="Filter table"', $html);
    }

    #[Test]
    public function not_filterable_by_default(): void
    {
        $table = new DataTableComponent();
        $table->rows([['x' => 1]]);

        $html = $table->render();

        self::assertStringNotContainsString('type="search"', $html);
    }

    #[Test]
    public function sortable_headers(): void
    {
        $table = new DataTableComponent();
        $table->rows([['a' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('aria-sort="none"', $html);
    }

    #[Test]
    public function non_sortable_headers(): void
    {
        $table = new DataTableComponent();
        $table->sortable(false)->rows([['a' => 1]]);

        $html = $table->render();

        self::assertStringNotContainsString('aria-sort', $html);
    }

    #[Test]
    public function tag_name(): void
    {
        $table = new DataTableComponent();

        self::assertSame('pulsar-data-table', $table->tagName());
    }

    #[Test]
    public function escapes_cell_values(): void
    {
        $table = new DataTableComponent();
        $table->rows([['html' => '<script>alert("xss")</script>']]);

        $html = $table->render();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function custom_attributes(): void
    {
        $table = new DataTableComponent();
        $table->attr('id', 'users-table')->rows([['a' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('id="users-table"', $html);
    }

    #[Test]
    public function page_size_in_props(): void
    {
        $table = new DataTableComponent();
        $table->pageSize(50)->rows([['a' => 1]]);

        $html = $table->render();

        self::assertStringContainsString('data-props=', $html);
        self::assertStringContainsString('50', $html);
    }
}
