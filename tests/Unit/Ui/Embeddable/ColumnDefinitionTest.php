<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\ColumnDefinition;

#[CoversClass(ColumnDefinition::class)]
final class ColumnDefinitionTest extends TestCase
{
    #[Test]
    public function defaultsAreSortableAndFilterable(): void
    {
        $col = new ColumnDefinition(key: 'name', label: 'Name');

        self::assertSame('name', $col->key);
        self::assertSame('Name', $col->label);
        self::assertTrue($col->sortable);
        self::assertTrue($col->filterable);
        self::assertNull($col->format);
    }

    #[Test]
    public function customFlagsAndFormat(): void
    {
        $col = new ColumnDefinition(
            key: 'created_at',
            label: 'Created',
            sortable: true,
            filterable: false,
            format: 'datetime',
        );

        self::assertTrue($col->sortable);
        self::assertFalse($col->filterable);
        self::assertSame('datetime', $col->format);
    }

    #[Test]
    public function nonSortableNonFilterableColumn(): void
    {
        $col = new ColumnDefinition('actions', 'Actions', sortable: false, filterable: false);

        self::assertFalse($col->sortable);
        self::assertFalse($col->filterable);
    }
}
