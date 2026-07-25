<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;

use function is_numeric;
use function max;

/**
 * Configuration for the transactional-outbox event delivery (ADR-0027).
 *
 * Opt-in (`enabled` defaults to false) so existing deployments keep synchronous
 * event delivery unchanged. When enabled, the event wiring binds the OutboxPort,
 * the integration-event bus and the relay; producers store integration events
 * via the OutboxPort inside their domain transaction and the relay publishes them
 * after commit.
 */
final readonly class OutboxConfig
{
    public function __construct(
        public bool $enabled = false,
        public int $batchSize = 100,
        public int $maxPublishAttempts = 5,
    ) {}

    /**
     * @param array{enabled?: bool|int|string, batch_size?: int|string, max_publish_attempts?: int|string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $batchSize = $data['batch_size'] ?? 100;
        $maxAttempts = $data['max_publish_attempts'] ?? 5;

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            batchSize: max(1, is_numeric($batchSize) ? (int) $batchSize : 100),
            maxPublishAttempts: max(1, is_numeric($maxAttempts) ? (int) $maxAttempts : 5),
        );
    }
}
