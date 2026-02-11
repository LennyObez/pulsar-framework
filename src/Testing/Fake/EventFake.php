<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use Closure;
use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;

use function array_filter;
use function array_values;
use function count;
use function get_class;
use function implode;
use function sprintf;

/**
 * Fake event dispatcher that records all dispatched events for assertion.
 *
 * Captures both raw events (PSR-14 dispatch) and envelope-wrapped events,
 * providing assertion methods with detailed failure messages.
 */
#[Api(since: '1.0.0')]
final class EventFake implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $dispatchedEvents = [];

    /** @var list<EventEnvelope> */
    private array $dispatchedEnvelopes = [];

    public function dispatch(object $event): object
    {
        $this->dispatchedEvents[] = $event;

        return $event;
    }

    public function dispatchEnvelope(EventEnvelope $envelope): EventEnvelope
    {
        $this->dispatchedEnvelopes[] = $envelope;
        $this->dispatchedEvents[] = $envelope;

        return $envelope;
    }

    /**
     * Assert that an event of the given class was dispatched.
     *
     * @param class-string $eventClass
     * @param int|null $count Exact number expected (null = at least one)
     */
    public function assertDispatched(string $eventClass, ?int $count = null): void
    {
        $matching = $this->eventsOfType($eventClass);
        $matchCount = count($matching);

        if ($count !== null) {
            Assert::assertSame(
                $count,
                $matchCount,
                sprintf(
                    "Expected %d dispatch(es) of [%s], but %d occurred.\nDispatched events: %s",
                    $count,
                    $eventClass,
                    $matchCount,
                    $this->formatDispatchedList(),
                ),
            );
        } else {
            Assert::assertGreaterThan(
                0,
                $matchCount,
                sprintf(
                    "Expected event [%s] to be dispatched, but it was not.\nDispatched events: %s",
                    $eventClass,
                    $this->formatDispatchedList(),
                ),
            );
        }
    }

    /**
     * Assert that an event matching a callback was dispatched.
     *
     * @param class-string $eventClass
     * @param Closure(object): bool $callback
     */
    public function assertDispatchedWith(string $eventClass, Closure $callback): void
    {
        $matching = array_filter(
            $this->eventsOfType($eventClass),
            $callback,
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected event [%s] matching callback to be dispatched, but none matched.\nDispatched [%s] events: %d",
                $eventClass,
                $eventClass,
                count($this->eventsOfType($eventClass)),
            ),
        );
    }

    /**
     * Assert that an event was NOT dispatched.
     *
     * @param class-string $eventClass
     */
    public function assertNotDispatched(string $eventClass): void
    {
        $matching = $this->eventsOfType($eventClass);

        Assert::assertCount(
            0,
            $matching,
            sprintf(
                'Expected event [%s] NOT to be dispatched, but it was dispatched %d time(s).',
                $eventClass,
                count($matching),
            ),
        );
    }

    /**
     * Assert that no events were dispatched at all.
     */
    public function assertNothingDispatched(): void
    {
        Assert::assertCount(
            0,
            $this->dispatchedEvents,
            sprintf(
                "Expected no events to be dispatched, but %d were.\nDispatched events: %s",
                count($this->dispatchedEvents),
                $this->formatDispatchedList(),
            ),
        );
    }

    /**
     * Assert that an envelope with the given event type was dispatched.
     */
    public function assertEnvelopeDispatched(string $eventType, ?int $count = null): void
    {
        $matching = array_filter(
            $this->dispatchedEnvelopes,
            static fn(EventEnvelope $e): bool => $e->eventType === $eventType,
        );
        $matchCount = count($matching);

        if ($count !== null) {
            Assert::assertSame(
                $count,
                $matchCount,
                sprintf(
                    'Expected %d envelope(s) with type [%s], but %d were dispatched.',
                    $count,
                    $eventType,
                    $matchCount,
                ),
            );
        } else {
            Assert::assertGreaterThan(
                0,
                $matchCount,
                sprintf(
                    "Expected envelope with type [%s] to be dispatched, but it was not.\nDispatched envelope types: %s",
                    $eventType,
                    implode(', ', array_map(
                        static fn(EventEnvelope $e): string => $e->eventType,
                        $this->dispatchedEnvelopes,
                    )),
                ),
            );
        }
    }

    /**
     * Get all dispatched events.
     *
     * @return list<object>
     */
    public function dispatched(): array
    {
        return $this->dispatchedEvents;
    }

    /**
     * Get all dispatched events of a specific type.
     *
     * @param class-string $eventClass
     *
     * @return list<object>
     */
    public function eventsOfType(string $eventClass): array
    {
        return array_values(array_filter(
            $this->dispatchedEvents,
            static fn(object $event): bool => $event instanceof $eventClass,
        ));
    }

    /**
     * Get all dispatched envelopes.
     *
     * @return list<EventEnvelope>
     */
    public function envelopes(): array
    {
        return $this->dispatchedEnvelopes;
    }

    /**
     * Reset all recorded state.
     */
    public function reset(): void
    {
        $this->dispatchedEvents = [];
        $this->dispatchedEnvelopes = [];
    }

    private function formatDispatchedList(): string
    {
        if ($this->dispatchedEvents === []) {
            return '(none)';
        }

        $classes = [];

        foreach ($this->dispatchedEvents as $event) {
            $class = get_class($event);

            if (!isset($classes[$class])) {
                $classes[$class] = 0;
            }

            ++$classes[$class];
        }

        $parts = [];

        foreach ($classes as $class => $classCount) {
            $parts[] = sprintf('%s (%dx)', $class, $classCount);
        }

        return implode(', ', $parts);
    }
}
