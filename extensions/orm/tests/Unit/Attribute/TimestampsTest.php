<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Timestamps;

final class TimestampsTest extends TestCase
{
    #[Test]
    public function defaultColumnNames(): void
    {
        $attr = new Timestamps();

        self::assertSame('created_at', $attr->createdAt);
        self::assertSame('updated_at', $attr->updatedAt);
    }

    #[Test]
    public function customColumnNames(): void
    {
        $attr = new Timestamps(createdAt: 'date_created', updatedAt: 'date_modified');

        self::assertSame('date_created', $attr->createdAt);
        self::assertSame('date_modified', $attr->updatedAt);
    }
}
