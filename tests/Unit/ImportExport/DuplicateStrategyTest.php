<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ImportExport\DuplicateStrategy;

#[CoversClass(DuplicateStrategy::class)]
final class DuplicateStrategyTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = DuplicateStrategy::cases();

        self::assertCount(3, $cases);
        self::assertSame('skip', DuplicateStrategy::Skip->value);
        self::assertSame('overwrite', DuplicateStrategy::Overwrite->value);
        self::assertSame('fail', DuplicateStrategy::Fail->value);
    }

    #[Test]
    public function tryFromResolvesValidValues(): void
    {
        self::assertSame(DuplicateStrategy::Skip, DuplicateStrategy::tryFrom('skip'));
        self::assertSame(DuplicateStrategy::Overwrite, DuplicateStrategy::tryFrom('overwrite'));
        self::assertSame(DuplicateStrategy::Fail, DuplicateStrategy::tryFrom('fail'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(DuplicateStrategy::tryFrom('merge'));
        self::assertNull(DuplicateStrategy::tryFrom(''));
    }
}
