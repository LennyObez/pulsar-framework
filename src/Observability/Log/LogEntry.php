<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable log entry value object.
 */
#[Api(since: '1.0.0')]
readonly class LogEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public LogLevel $level,
        public string $message,
        public array $context,
        public string $channel,
        public DateTimeImmutable $timestamp,
    ) {}

    /**
     * Create a new log entry with UTC timestamp.
     *
     * @param array<string, mixed> $context
     *
     * @throws DateMalformedStringException
     */
    #[NoDiscard]
    public static function create(
        LogLevel $level,
        string $message,
        array $context = [],
        string $channel = 'app',
    ): self {
        return new self(
            level: $level,
            message: $message,
            context: $context,
            channel: $channel,
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
