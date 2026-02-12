<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\SortDirection;

final class SortDirectionTest extends TestCase
{
    #[Test]
    public function backingValues(): void
    {
        self::assertSame('ASC', SortDirection::Asc->value);
        self::assertSame('DESC', SortDirection::Desc->value);
    }

    #[Test]
    public function totalCaseCount(): void
    {
        self::assertCount(2, SortDirection::cases());
    }
}
