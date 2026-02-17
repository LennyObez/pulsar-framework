<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ListResourceResult;

final class ListResourceResultTest extends TestCase
{
    #[Test]
    public function construction_with_data(): void
    {
        $result = new ListResourceResult(
            data: [
                ['id' => '1', 'name' => 'Alice'],
                ['id' => '2', 'name' => 'Bob'],
            ],
            total: 42,
            page: 1,
            perPage: 25,
            totalPages: 2,
        );

        self::assertCount(2, $result->data);
        self::assertSame(42, $result->total);
        self::assertSame(1, $result->page);
        self::assertSame(25, $result->perPage);
        self::assertSame(2, $result->totalPages);
    }

    #[Test]
    public function empty_result(): void
    {
        $result = new ListResourceResult(
            data: [],
            total: 0,
            page: 1,
            perPage: 25,
            totalPages: 0,
        );

        self::assertSame([], $result->data);
        self::assertSame(0, $result->total);
        self::assertSame(0, $result->totalPages);
    }
}
