<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\GoalType;

final class GoalTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('page_visit', GoalType::PageVisit->value);
        self::assertSame('custom_event', GoalType::CustomEvent->value);
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(GoalType::PageVisit, GoalType::from('page_visit'));
        self::assertSame(GoalType::CustomEvent, GoalType::from('custom_event'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(GoalType::tryFrom('revenue'));
    }
}
