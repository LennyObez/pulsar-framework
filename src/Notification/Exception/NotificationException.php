<?php

declare(strict_types=1);

namespace Pulsar\Notification\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * General exception for notification operations.
 * @api
 */
#[Api(since: '1.0.0')]
final class NotificationException extends RuntimeException
{
    #[NoDiscard]
    public static function deliveryFailed(string $channel, string $notifiableId, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Notification delivery failed on channel "%s" for notifiable "%s"',
                $channel,
                $notifiableId,
            ),
            previous: $previous,
        );
    }

    #[NoDiscard]
    public static function channelNotAvailable(string $channel, string $reason): self
    {
        return new self(
            sprintf('Notification channel "%s" is not available: %s', $channel, $reason),
        );
    }

    #[NoDiscard]
    public static function rateLimitExceeded(string $notifiableId, string $channel, int $limit): self
    {
        return new self(
            sprintf(
                'Rate limit exceeded for notifiable "%s" on channel "%s" (%d per minute)',
                $notifiableId,
                $channel,
                $limit,
            ),
        );
    }

    #[NoDiscard]
    public static function legalBasisMissing(string $notificationType): self
    {
        return new self(
            sprintf(
                'Legal basis is required but not provided for notification "%s"',
                $notificationType,
            ),
        );
    }

    #[NoDiscard]
    public static function preferenceViolation(string $notifiableId, string $channel): self
    {
        return new self(
            sprintf(
                'Notifiable "%s" has opted out of channel "%s"',
                $notifiableId,
                $channel,
            ),
        );
    }
}
