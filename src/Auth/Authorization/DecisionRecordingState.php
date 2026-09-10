<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Pulsar\Api\Internal;

use function array_shift;

/**
 * One call stack's view of {@see Gate::record()}.
 *
 * The Gate keeps one of these per Fiber, for exactly as long as an outer
 * `record()` frame is open on that Fiber. Its existence *is* the re-entry
 * guard: a `record()` call that finds a state for its own Fiber is a call the
 * sink made from inside the record it was handed, and a call that finds none is
 * the outer one.
 *
 * ## Why this is not three properties on the Gate
 *
 * It was, and under a Fiber-interleaving runtime that made the guard mean the
 * wrong thing. `$recording` as an instance property answers "is this Gate
 * recording anywhere", not "is this call stack inside a record" — so a decision
 * reached by Fiber B while Fiber A's sink was suspended read as B re-entering
 * A's record. B's decision went into A's nested queue, was attributed to A's
 * drain, counted against A's ceiling, and was discarded outright by A's reset
 * if the ceiling had already been reached. Two Fibers deciding at once lost
 * decisions and cross-attributed the ones they kept.
 *
 * A Fiber's call stack is linear, so per-Fiber state is per-call-stack state:
 * within one Fiber, finding a state open can only mean this frame is nested
 * inside another. That is the property the guard needs and the one an instance
 * property cannot have.
 *
 * ## Why the queue is not two public fields
 *
 * It was, and the Gate reached in and mutated both: it appended to `$nested`,
 * incremented `$refused`, and `array_shift()`ed the queue it had filled. Three
 * writes to another object's state, in a class whose whole job is to be correct
 * about ordering under Fiber interleaving — and `array_shift()` on a foreign
 * property is a write no static analysis can attribute to an owner, so nothing
 * could check the invariant that a shifted decision is one that was queued.
 * The fields stay readable, because the Gate's ceiling test and its
 * end-of-frame arithmetic are questions about the queue rather than changes to
 * it, but every write now goes through a method here.
 */
#[Internal]
final class DecisionRecordingState
{
    /**
     * Decisions reached on this call stack while the outer record was open,
     * awaiting the drain at the end of it.
     *
     * @var list<AuthorizationDecision>
     */
    public private(set) array $nested = [];

    /** Nested decisions this outer record's ceiling refused. */
    public private(set) int $refused = 0;

    /**
     * Hold a decision reached while this frame's record was open.
     *
     * The caller decides whether there is room; {@see refuse()} is the other
     * half of that decision and the two are deliberately separate, because the
     * ceiling is the Gate's policy and the queue is this object's state.
     */
    public function queue(AuthorizationDecision $decision): void
    {
        $this->nested[] = $decision;
    }

    /**
     * Record that a nested decision was dropped because the ceiling was reached.
     */
    public function refuse(): void
    {
        ++$this->refused;
    }

    /**
     * Take the decision queued longest, or null when the queue is empty.
     *
     * FIFO, because these are audit records of decisions that happened in an
     * order: draining them last-first would report the sequence backwards.
     */
    public function shift(): ?AuthorizationDecision
    {
        return array_shift($this->nested);
    }
}
