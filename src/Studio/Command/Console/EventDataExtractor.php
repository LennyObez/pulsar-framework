<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function is_int;
use function is_numeric;
use function is_string;

use Pulsar\Api\Internal;

/**
 * Safely extracts typed values from raw event data arrays.
 *
 * Handles mixed types from database queries and provides defaults.
 */
#[Internal]
final readonly class EventDataExtractor
{
    /**
     * @param array<string, mixed> $event
     */
    public function __construct(
        private array $event,
    ) {}

    public function eventType(): string
    {
        $raw = $this->event['event_type'] ?? 'unknown';

        return is_string($raw) ? $raw : 'unknown';
    }

    public function eventId(): string
    {
        $raw = $this->event['event_id'] ?? '';

        return is_string($raw) ? $raw : '';
    }

    public function timestampUs(): int
    {
        $raw = $this->event['timestamp_us'] ?? 0;

        return is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 0);
    }

    public function requestId(): string
    {
        $raw = $this->event['request_id'] ?? '';

        return is_string($raw) ? $raw : '';
    }

    public function id(): int
    {
        $raw = $this->event['id'] ?? 0;

        return is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 0);
    }

    /**
     * Get formatted time string from timestamp.
     */
    public function formattedTime(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format, (int) ($this->timestampUs() / 1_000_000));
    }
}
