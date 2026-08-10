<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal\Outbox;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Event\EventEnvelope;
use Pulsar\Saga\Port\IntegrationEventBusPort;
use SodiumException;
use Throwable;

use function strlen;
use function substr;

/**
 * Drains the {@see DatabaseOutboxPort} table and publishes pending
 * envelopes to the {@see IntegrationEventBusPort}.
 *
 * ADR-0027: this is the second half of the transactional
 * outbox pattern. The producer side persists the envelope inside
 * the domain transaction; this relay reads pending rows after
 * commit and forwards them to the bus. On failure, the row stays
 * pending and the next tick retries — at-least-once semantics with
 * idempotency carried by `event_id`.
 *
 * The relay is intentionally a plain class (no command framework
 * dependency) so the same `tick()` can run from a CLI worker, a
 * cron, a queue consumer, or an in-process supervisor without
 * coupling to any one runtime.
 */
#[Internal]
final readonly class OutboxRelay
{
    public function __construct(
        private DatabaseOutboxPort $outbox,
        private IntegrationEventBusPort $bus,
        private int $batchSize = 100,
        private int $maxPublishAttempts = 5,
    ) {}

    /**
     * Process a single batch of pending events.
     *
     * Envelopes that reach {@see $maxPublishAttempts} are dead-lettered by
     * {@see DatabaseOutboxPort::recordFailure()} and excluded from subsequent
     * batches, so a permanently-failing "poison" envelope can no longer starve
     * the FIFO-ordered healthy events behind it.
     *
     * @return OutboxRelayTickResult Counts of published / failed / dead-lettered
     *                                events so callers can drive metrics or
     *                                back-pressure logic.
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function tick(): OutboxRelayTickResult
    {
        $pending = $this->outbox->pendingForRelay($this->batchSize, $this->maxPublishAttempts);

        $published = 0;
        $failed = 0;
        $deadLettered = 0;

        foreach ($pending as $pendingEnvelope) {
            $envelope = $pendingEnvelope->envelope;

            try {
                $this->publish($envelope);
                $this->outbox->markPublished($envelope->eventId);
                $published++;
            } catch (Throwable $error) {
                // Determined from the pre-failure count fetched alongside the
                // envelope, so counting dead-letters costs no extra query. The
                // same threshold drives recordFailure()'s dead_lettered_at stamp.
                $isDeadLettered = ($pendingEnvelope->publishAttempts + 1) >= $this->maxPublishAttempts;

                $this->outbox->recordFailure(
                    $envelope->eventId,
                    $this->describeError($error),
                    $this->maxPublishAttempts,
                );
                $failed++;

                if ($isDeadLettered) {
                    $deadLettered++;
                }
            }
        }

        return new OutboxRelayTickResult(
            attempted: $published + $failed,
            published: $published,
            failed: $failed,
            deadLettered: $deadLettered,
        );
    }

    private function publish(EventEnvelope $envelope): void
    {
        $this->bus->publish($envelope->eventType, $envelope->payload);
    }

    /**
     * Bound the recorded error message so a malicious or accidental
     * megabyte exception payload doesn't blow up the column.
     */
    private function describeError(Throwable $error): string
    {
        $message = $error::class . ': ' . $error->getMessage();
        if (strlen($message) > 1024) {
            return substr($message, 0, 1021) . '...';
        }
        return $message;
    }
}
