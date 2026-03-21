<?php

declare(strict_types=1);

namespace Pulsar\Observability;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Observability\Exception\TimerException;

use function array_key_exists;
use function array_sum;
use function hrtime;

/**
 * Nested timer for fine-grained profiling of hot code paths.
 *
 * Void Drift Phase 3 combat needs to measure each sub-step of a
 * single tick (projectiles, weapons, hazards, shields, AI, boarding,
 * teleporter, flee) to isolate bottlenecks. Rather than scattering
 * `microtime(true)` calls, callers open a `Timer`, drop `mark()`s
 * between work units, and close it with `stop()`:
 *
 * ```php
 * $timer = Timer::start('combat.tick');
 * // …projectile work…
 * $timer->mark('projectiles');
 * // …weapon work…
 * $timer->mark('weapons');
 * $timer->stop();
 *
 * $snapshot = $timer->snapshot();
 * // ['total_ms' => 2.31, 'marks' => ['projectiles' => 0.47, 'weapons' => 1.84]]
 * ```
 *
 * Marks are relative to the previous mark (or to `start()` for the
 * first one), so the sum of mark durations equals the total minus the
 * tail after the last mark. `snapshot()` returns durations in
 * milliseconds as floats with nanosecond resolution from `hrtime()`.
 *
 * Thread-local by design: each `Timer` is a private instance, so
 * concurrent requests (in persistent runtimes) cannot observe each
 * other's timings. The previous `microtime(true)` idiom was safe but
 * ergonomically poor — this class is the sanctioned substitute.
 * @api
 */
#[Api(since: '1.0.0')]
final class Timer
{
    private readonly int $startNs;
    private int $lastMarkNs;

    /** @var array<string, float> mark name => milliseconds elapsed since the previous mark */
    private array $marks = [];

    private ?int $stoppedAtNs = null;

    private function __construct(
        public readonly string $label,
    ) {
        $this->startNs = hrtime(true);
        $this->lastMarkNs = $this->startNs;
    }

    /**
     * Open a new timer with the given label.
     *
     * The label is a stable identifier used by `snapshot()` and by any
     * downstream exporter (logs, metrics, traces). Use dotted
     * hierarchical names like `combat.tick` or `render.pipeline`.
     */
    #[NoDiscard]
    public static function start(string $label): self
    {
        return new self($label);
    }

    /**
     * Record a sub-timing checkpoint.
     *
     * The mark captures the elapsed time since the previous mark (or
     * since `start()` for the first mark). Duplicate mark names are
     * rejected to keep the snapshot unambiguous — callers that need
     * to measure the same label multiple times should use distinct
     * names (`loop.1`, `loop.2`) or open nested timers instead.
     *
     * @throws TimerException If `$name` is empty, already recorded, or
     *                        the timer has already been stopped.
     */
    public function mark(string $name): void
    {
        if ($this->stoppedAtNs !== null) {
            throw TimerException::alreadyStopped($this->label);
        }

        if ($name === '') {
            throw TimerException::emptyMarkName($this->label);
        }

        if (array_key_exists($name, $this->marks)) {
            throw TimerException::duplicateMark($this->label, $name);
        }

        $now = hrtime(true);
        $this->marks[$name] = self::nsToMs($now - $this->lastMarkNs);
        $this->lastMarkNs = $now;
    }

    /**
     * Close the timer and freeze the total duration.
     *
     * `snapshot()` can still be called afterwards. Calling `stop()` a
     * second time is a no-op to make the happy-path callsite forgiving
     * when `finally {}` blocks or shutdown hooks race.
     */
    public function stop(): void
    {
        if ($this->stoppedAtNs !== null) {
            return;
        }

        $this->stoppedAtNs = hrtime(true);
    }

    /**
     * Export the timer state as an associative array.
     *
     * Shape:
     * ```
     * [
     *     'label'    => 'combat.tick',
     *     'total_ms' => 2.34,
     *     'marks'    => ['projectiles' => 0.47, 'weapons' => 1.84],
     * ]
     * ```
     *
     * If the timer has not been stopped, `total_ms` is computed from
     * the current wall-clock reading so observers can peek mid-flight.
     *
     * @return array{label: string, total_ms: float, marks: array<string, float>}
     */
    #[NoDiscard]
    public function snapshot(): array
    {
        $endNs = $this->stoppedAtNs ?? hrtime(true);

        return [
            'label' => $this->label,
            'total_ms' => self::nsToMs($endNs - $this->startNs),
            'marks' => $this->marks,
        ];
    }

    /**
     * Total milliseconds elapsed so far (or until `stop()`).
     */
    #[NoDiscard]
    public function totalMs(): float
    {
        $endNs = $this->stoppedAtNs ?? hrtime(true);

        return self::nsToMs($endNs - $this->startNs);
    }

    /**
     * Sum of all mark durations. Equals `totalMs()` minus the time
     * elapsed after the last mark.
     */
    #[NoDiscard]
    public function marksSumMs(): float
    {
        return array_sum($this->marks);
    }

    /**
     * Convert a nanosecond duration to milliseconds.
     *
     * Accepts `int|float` because `hrtime(true)` returns `float` on 32-bit
     * platforms where the int range is too small to hold the timestamp.
     * On 64-bit platforms (where Pulsar runs in production) the value is
     * always `int`; the union keeps the static analyser honest about the
     * 32-bit fallback path.
     */
    private static function nsToMs(int|float $nanoseconds): float
    {
        return $nanoseconds / 1_000_000.0;
    }
}
