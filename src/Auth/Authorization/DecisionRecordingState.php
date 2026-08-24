<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Pulsar\Api\Internal;

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
    public array $nested = [];

    /** Nested decisions this outer record's ceiling refused. */
    public int $refused = 0;
}
