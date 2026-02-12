<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\FetchPlan;

final class FetchPlanTest extends TestCase
{
    #[Test]
    public function withCreatesFromList(): void
    {
        $plan = FetchPlan::with(['posts', 'comments']);

        self::assertTrue($plan->has('posts'));
        self::assertTrue($plan->has('comments'));
        self::assertFalse($plan->has('tags'));
        self::assertSame(['posts', 'comments'], $plan->relationNames());
    }

    #[Test]
    public function withNestedCreatesNestedPlans(): void
    {
        $nested = FetchPlan::with(['author']);
        $plan = FetchPlan::withNested(['posts' => $nested, 'tags' => null]);

        self::assertTrue($plan->has('posts'));
        self::assertTrue($plan->has('tags'));
        self::assertSame($nested, $plan->nested('posts'));
        self::assertNull($plan->nested('tags'));
    }

    #[Test]
    public function noneCreatesEmptyPlan(): void
    {
        $plan = FetchPlan::none();

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->relationNames());
    }

    #[Test]
    public function mergesCombineBothPlans(): void
    {
        $a = FetchPlan::with(['posts']);
        $b = FetchPlan::with(['comments']);

        $merged = $a->merge($b);

        self::assertTrue($merged->has('posts'));
        self::assertTrue($merged->has('comments'));
    }

    #[Test]
    public function isEmptyReturnsTrueForNoRelations(): void
    {
        self::assertTrue(FetchPlan::none()->isEmpty());
        self::assertFalse(FetchPlan::with(['x'])->isEmpty());
    }

    #[Test]
    public function nestedReturnsNullForMissingRelation(): void
    {
        $plan = FetchPlan::with(['posts']);

        self::assertNull($plan->nested('nonexistent'));
    }
}
