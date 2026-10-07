<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\SoftDelete;

final class SoftDeleteTest extends TestCase
{
    #[Test]
    public function defaultColumn(): void
    {
        $attr = new SoftDelete();

        self::assertSame('deleted_at', $attr->column);
    }

    #[Test]
    public function customColumn(): void
    {
        $attr = new SoftDelete(column: 'removed_at');

        self::assertSame('removed_at', $attr->column);
    }
}
