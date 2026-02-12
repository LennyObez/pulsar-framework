<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Table;

final class TableTest extends TestCase
{
    #[Test]
    public function storesNameAndDefaultSchema(): void
    {
        $attr = new Table(name: 'users');

        self::assertSame('users', $attr->name);
        self::assertNull($attr->schema);
    }

    #[Test]
    public function storesCustomSchema(): void
    {
        $attr = new Table(name: 'orders', schema: 'billing');

        self::assertSame('orders', $attr->name);
        self::assertSame('billing', $attr->schema);
    }
}
