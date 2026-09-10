<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\AuthorizationDecision;
use Pulsar\Auth\Authorization\DecisionRecordingState;
use ReflectionProperty;

use function array_column;

/**
 * The nested-decision queue, driven directly rather than through the Gate.
 *
 * The Gate used to reach into this object and mutate it: append to `$nested`,
 * increment `$refused`, and `array_shift()` the queue it had filled. Every one
 * of those is now a method here, and the properties are `private(set)` — so the
 * invariants below are the object's own to keep, and the tests that state them
 * do not have to arrange a whole authorization decision to reach them.
 *
 * `array_shift()` through a foreign property was also the write no static
 * analysis could attribute to an owner, which is how the queue ended up with a
 * mutability verdict of "undecidable" instead of a verdict.
 */
#[CoversClass(DecisionRecordingState::class)]
final class DecisionRecordingStateTest extends TestCase
{
    #[Test]
    public function aFreshStateHoldsNothingAndHasRefusedNothing(): void
    {
        $state = new DecisionRecordingState();

        self::assertSame([], $state->nested);
        self::assertSame(0, $state->refused);
    }

    #[Test]
    public function queuedDecisionsComeBackInTheOrderTheyWereReached(): void
    {
        $state = new DecisionRecordingState();
        $state->queue(self::decision('first'));
        $state->queue(self::decision('second'));
        $state->queue(self::decision('third'));

        self::assertSame(
            ['first', 'second', 'third'],
            array_column($state->nested, 'permission'),
        );

        // FIFO: these are audit records of decisions that happened in an order,
        // and draining them last-first would report the sequence backwards.
        // Drained the way the Gate drains it — until the queue answers null —
        // so the order and the emptying are one assertion rather than two.
        $drained = [];

        while (($next = $state->shift()) !== null) {
            $drained[] = $next->permission;
        }

        self::assertSame(['first', 'second', 'third'], $drained);
        self::assertSame([], $state->nested);
    }

    #[Test]
    public function shiftingAnEmptyQueueAnswersNullRatherThanFailing(): void
    {
        $state = new DecisionRecordingState();

        self::assertNull($state->shift());

        $state->queue(self::decision('only'));

        self::assertSame('only', $state->shift()?->permission);
        self::assertNull($state->shift());
        self::assertSame([], $state->nested);
    }

    #[Test]
    public function aRefusalIsCountedAndDoesNotEnterTheQueue(): void
    {
        $state = new DecisionRecordingState();
        $state->queue(self::decision('kept'));
        $state->refuse();
        $state->refuse();

        self::assertSame(2, $state->refused);
        self::assertSame(['kept'], array_column($state->nested, 'permission'));
    }

    /**
     * The whole point of the change: the Gate can no longer put a decision into
     * the queue, or drop one out of it, without going through this object.
     */
    #[Test]
    public function neitherFieldCanBeWrittenFromOutside(): void
    {
        self::assertTrue(
            new ReflectionProperty(DecisionRecordingState::class, 'nested')->isPrivateSet(),
            '$nested is writable from outside again, so a caller can queue a decision the object never saw',
        );
        self::assertTrue(
            new ReflectionProperty(DecisionRecordingState::class, 'refused')->isPrivateSet(),
            '$refused is writable from outside again, so the refusal count is whatever a caller last wrote',
        );
    }

    private static function decision(string $permission): AuthorizationDecision
    {
        return new AuthorizationDecision(
            identityId: 'user-1',
            permission: $permission,
            resource: null,
            allowed: true,
            reason: 'RBAC',
            decidedAtUnix: 1_770_000_000.0,
        );
    }
}
