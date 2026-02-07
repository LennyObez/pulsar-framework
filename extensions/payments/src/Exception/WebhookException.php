<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Exception;

use NoDiscard;
use RuntimeException;

use function sprintf;

/**
 * Webhook processing exceptions.
 */
final class WebhookException extends RuntimeException
{
    #[NoDiscard]
    public static function invalidSignature(): self
    {
        return new self('Webhook signature verification failed');
    }

    #[NoDiscard]
    public static function expiredTimestamp(int $age, int $tolerance): self
    {
        return new self(sprintf(
            'Webhook timestamp too old: %d seconds (tolerance: %d)',
            $age,
            $tolerance,
        ));
    }

    #[NoDiscard]
    public static function malformedHeader(string $reason): self
    {
        return new self(sprintf('Malformed webhook signature header: %s', $reason));
    }

    #[NoDiscard]
    public static function concurrentClaim(string $eventId): self
    {
        return new self(sprintf(
            'Webhook event "%s" is currently being processed',
            $eventId,
        ));
    }

    #[NoDiscard]
    public static function handlerFailed(string $eventId, string $reason): self
    {
        return new self(sprintf(
            'Webhook handler failed for event "%s": %s',
            $eventId,
            $reason,
        ));
    }
}
