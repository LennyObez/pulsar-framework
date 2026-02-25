<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Schema\ForeignKeyDefinition;

final class ForeignKeyDefinitionTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $fk = new ForeignKeyDefinition(
            name: 'fk_orders_user_id',
            columns: ['user_id'],
            referencedTable: 'users',
            referencedColumns: ['id'],
        );

        self::assertSame('fk_orders_user_id', $fk->name);
        self::assertSame(['user_id'], $fk->columns);
        self::assertSame('users', $fk->referencedTable);
        self::assertSame(['id'], $fk->referencedColumns);
        self::assertSame('RESTRICT', $fk->onDelete);
        self::assertSame('RESTRICT', $fk->onUpdate);
    }

    #[Test]
    public function constructionWithCascade(): void
    {
        $fk = new ForeignKeyDefinition(
            name: 'fk_comments_post_id',
            columns: ['post_id'],
            referencedTable: 'posts',
            referencedColumns: ['id'],
            onDelete: 'CASCADE',
            onUpdate: 'SET NULL',
        );

        self::assertSame('CASCADE', $fk->onDelete);
        self::assertSame('SET NULL', $fk->onUpdate);
    }
}
