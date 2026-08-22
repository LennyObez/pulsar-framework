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
final readonly class OutboxConfig implements ReportsUnknownKeys
{
    /** Keys read from the `outbox` sub-array of config/event.php. */
    private const array KNOWN_KEYS = ['enabled', 'batch_size', 'max_publish_attempts'];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `outbox` array that this
     *     DTO does not read — a misspelled `enabled` leaves the transactional outbox
     *     off, so integration events are never relayed and nothing says why.
     */
    public function __construct(
        public bool $enabled = false,
        public int $batchSize = 100,
        public int $maxPublishAttempts = 5,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
