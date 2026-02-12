<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Schema\IndexDefinition;

final class IndexDefinitionTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $idx = new IndexDefinition(
            name: 'idx_users_email',
            columns: ['email'],
        );

        self::assertSame('idx_users_email', $idx->name);
        self::assertSame(['email'], $idx->columns);
        self::assertFalse($idx->unique);
    }

    #[Test]
    public function uniqueIndex(): void
    {
        $idx = new IndexDefinition(
            name: 'uniq_users_email',
            columns: ['email'],
            unique: true,
        );

        self::assertTrue($idx->unique);
    }

    #[Test]
    public function compositeIndex(): void
    {
        $idx = new IndexDefinition(
            name: 'idx_users_name_email',
            columns: ['first_name', 'last_name'],
        );

        self::assertSame(['first_name', 'last_name'], $idx->columns);
    }
}
