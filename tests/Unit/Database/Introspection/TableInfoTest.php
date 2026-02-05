<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Introspection\TableInfo;

#[CoversClass(TableInfo::class)]
final class TableInfoTest extends TestCase
{
    #[Test]
    public function constructWithNameOnly(): void
    {
        $info = new TableInfo(name: 'users');

        self::assertSame('users', $info->name);
        self::assertNull($info->schema);
    }

    #[Test]
    public function constructWithSchema(): void
    {
        $info = new TableInfo(name: 'users', schema: 'public');

        self::assertSame('users', $info->name);
        self::assertSame('public', $info->schema);
    }
}
