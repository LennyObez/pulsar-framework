<?php

declare(strict_types=1);

namespace Pulsar\Event\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for event system errors.
 */
#[Api(since: '1.0.0')]
final class EventException extends RuntimeException
{
    /**
     * Storm protection triggered: dispatch depth exceeded maximum.
     */
    #[NoDiscard]
    public static function stormDetected(string $eventClass, int $depth, int $maxDepth): self
    {
        return new self(sprintf(
            'Event storm detected: dispatching "%s" at depth %d exceeds maximum depth %d',
            $eventClass,
            $depth,
            $maxDepth,
        ));
    }

    /**
     * Loop detected: same event dispatched too many times in chain.
     */
    #[NoDiscard]
    public static function loopDetected(string $eventClass, int $occurrences, int $maxRepeats): self
    {
        return new self(sprintf(
            'Event loop detected: "%s" appeared %d times in dispatch chain (max %d)',
            $eventClass,
            $occurrences,
            $maxRepeats,
        ));
    }

    /**
     * Event requires envelope-based dispatch but was dispatched as plain object.
     */
    #[NoDiscard]
    public static function envelopeRequired(string $eventClass): self
    {
        return new self(sprintf(
            'Event "%s" requires envelope-based dispatch (use dispatchEnvelope() or wrap in EventEnvelope)',
            $eventClass,
        ));
    }

    /**
     * Invalid listener configuration.
     */
    #[NoDiscard]
    public static function invalidListener(string $reason): self
    {
        return new self(sprintf('Invalid event listener: %s', $reason));
    }

    /**
     * Event class name fails naming discipline lint.
     */
    #[NoDiscard]
    public static function invalidEventClassName(string $className, string $reason): self
    {
        return new self(sprintf(
            'Invalid event class name "%s": %s',
            $className,
            $reason,
        ));
    }
}
