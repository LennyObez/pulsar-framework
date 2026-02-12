<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\LockMode;

final class LockModeTest extends TestCase
{
    #[Test]
    public function allCasesHaveBackingValues(): void
    {
        self::assertSame('none', LockMode::None->value);
        self::assertSame('for_update', LockMode::ForUpdate->value);
        self::assertSame('for_share', LockMode::ForShare->value);
    }

    #[Test]
    public function totalCaseCount(): void
    {
        self::assertCount(3, LockMode::cases());
    }
}
