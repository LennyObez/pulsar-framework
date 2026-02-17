<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Sort;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Sort\SortDefinition;
use Pulsar\Api\Sort\SortDirection;
use Pulsar\Api\Sort\SortExpression;

#[CoversClass(SortDirection::class)]
#[CoversClass(SortDefinition::class)]
#[CoversClass(SortExpression::class)]
final class SortValueObjectTest extends TestCase
{
    // ── SortDirection ───────────────────────────────────────────────────

    #[Test]
    public function sortDirectionHasTwoCases(): void
    {
        self::assertCount(2, SortDirection::cases());
    }

    #[Test]
    #[DataProvider('sortDirectionProvider')]
    public function sortDirectionBackedValues(SortDirection $dir, string $expected): void
    {
        self::assertSame($expected, $dir->value);
    }

    /**
     * @return iterable<string, array{SortDirection, string}>
     */
    public static function sortDirectionProvider(): iterable
    {
        yield 'Ascending' => [SortDirection::Ascending, 'asc'];
        yield 'Descending' => [SortDirection::Descending, 'desc'];
    }

    #[Test]
    public function sortDirectionFromBackedValue(): void
    {
        self::assertSame(SortDirection::Ascending, SortDirection::from('asc'));
        self::assertSame(SortDirection::Descending, SortDirection::from('desc'));
    }

    // ── SortDefinition ──────────────────────────────────────────────────

    #[Test]
    public function sortDefinitionConstructionWithDefaults(): void
    {
        $def = new SortDefinition(column: 'created_at');

        self::assertSame('created_at', $def->column);
        self::assertNull($def->guard);
    }

    #[Test]
    public function sortDefinitionConstructionWithGuard(): void
    {
        $def = new SortDefinition(column: 'salary', guard: 'hr:read');

        self::assertSame('salary', $def->column);
        self::assertSame('hr:read', $def->guard);
    }

    #[Test]
    public function sortDefinitionRequiresAuthorizationWithGuard(): void
    {
        $def = new SortDefinition(column: 'salary', guard: 'hr:read');

        self::assertTrue($def->requiresAuthorization());
    }

    #[Test]
    public function sortDefinitionDoesNotRequireAuthorizationWithoutGuard(): void
    {
        $def = new SortDefinition(column: 'name');

        self::assertFalse($def->requiresAuthorization());
    }

    // ── SortExpression ──────────────────────────────────────────────────

    #[Test]
    public function sortExpressionConstructor(): void
    {
        $expr = new SortExpression(
            column: 'updated_at',
            direction: SortDirection::Descending,
        );

        self::assertSame('updated_at', $expr->column);
        self::assertSame(SortDirection::Descending, $expr->direction);
    }

    #[Test]
    public function sortExpressionCreateFactory(): void
    {
        $expr = SortExpression::create('name', SortDirection::Ascending);

        self::assertSame('name', $expr->column);
        self::assertSame(SortDirection::Ascending, $expr->direction);
    }

    #[Test]
    public function sortExpressionCreateDescending(): void
    {
        $expr = SortExpression::create('priority', SortDirection::Descending);

        self::assertSame('priority', $expr->column);
        self::assertSame(SortDirection::Descending, $expr->direction);
    }
}
