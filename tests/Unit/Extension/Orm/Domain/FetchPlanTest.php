<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\FetchPlan;

#[CoversClass(FetchPlan::class)]
final class FetchPlanTest extends TestCase
{
    #[Test]
    public function withCreatesShallowRelationPlan(): void
    {
        $plan = FetchPlan::with(['posts', 'comments']);

        self::assertTrue($plan->has('posts'));
        self::assertTrue($plan->has('comments'));
        self::assertFalse($plan->has('tags'));
        self::assertNull($plan->nested('posts'));
        self::assertSame(['posts', 'comments'], $plan->relationNames());
    }

    #[Test]
    public function withNestedCreatesDeepPlan(): void
    {
        $nested = FetchPlan::with(['tags']);
        $plan = FetchPlan::withNested([
            'posts' => $nested,
            'comments' => null,
        ]);

        self::assertTrue($plan->has('posts'));
        self::assertTrue($plan->has('comments'));
        self::assertSame($nested, $plan->nested('posts'));
        self::assertNull($plan->nested('comments'));
    }

    #[Test]
    public function noneCreatesEmptyPlan(): void
    {
        $plan = FetchPlan::none();

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->relationNames());
    }

    #[Test]
    public function isEmptyReturnsFalseForNonEmptyPlan(): void
    {
        $plan = FetchPlan::with(['posts']);

        self::assertFalse($plan->isEmpty());
    }

    #[Test]
    public function mergeCombinesTwoPlans(): void
    {
        $a = FetchPlan::with(['posts']);
        $b = FetchPlan::with(['comments']);

        $merged = $a->merge($b);

        self::assertTrue($merged->has('posts'));
        self::assertTrue($merged->has('comments'));
        self::assertSame(['posts', 'comments'], $merged->relationNames());
    }

    #[Test]
    public function mergeOverridesConflictingRelations(): void
    {
        $nested = FetchPlan::with(['tags']);
        $a = FetchPlan::withNested(['posts' => null]);
        $b = FetchPlan::withNested(['posts' => $nested]);

        $merged = $a->merge($b);

        self::assertSame($nested, $merged->nested('posts'));
    }

    #[Test]
    public function nestedReturnsNullForMissingRelation(): void
    {
        $plan = FetchPlan::with(['posts']);

        self::assertNull($plan->nested('nonexistent'));
    }
}
