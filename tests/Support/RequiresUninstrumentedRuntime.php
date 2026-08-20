<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support;

use function function_exists;
use function xdebug_code_coverage_started;

/**
 * A latency budget is only a statement about the code when nothing is profiling it.
 *
 * Under Xdebug path coverage the same checkout that completes in 190 ms takes 745 ms,
 * and a search that answers in 40 ms takes 337 ms. Those numbers do not describe a
 * regression; they describe the instrumentation. Asserting on them fails correct code,
 * which is worse than not asserting at all: it trains everyone to disbelieve the gate.
 *
 * So a test that measures wall-clock declares its own precondition here, rather than
 * being filtered out by a flag in one CI job. The guard travels with the assertion, it
 * cannot drift from the workflow file, and the test still runs and still asserts
 * everywhere coverage is not being collected — which is every run except the coverage
 * shards.
 */
trait RequiresUninstrumentedRuntime
{
    /**
     * True while a coverage driver is recording, so a test can drop a single
     * wall-clock assertion and keep the rest of its structural ones running. That
     * is preferable to skipping the whole case: the code path stays covered and
     * only the measurement that cannot be trusted is set aside.
     */
    protected function runtimeIsInstrumented(): bool
    {
        return function_exists('xdebug_code_coverage_started') && xdebug_code_coverage_started();
    }

    /**
     * Skip when a coverage driver is recording, because the clock is measuring it.
     */
    protected function requireUninstrumentedRuntime(): void
    {
        if ($this->runtimeIsInstrumented()) {
            self::markTestSkipped(
                'Wall-clock budgets are not measurable while code coverage is being collected: '
                . 'the elapsed time would describe the profiler rather than the code under test.',
            );
        }
    }
}
