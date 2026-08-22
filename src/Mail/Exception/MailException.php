<?php

declare(strict_types=1);

namespace Pulsar\Mail\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\MailEncryptionPolicy;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * General exception for mail operations.
 * @api
 */
#[Api(since: '1.0.0')]
final class MailException extends RuntimeException
{
    #[NoDiscard]
    public static function sendFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Mail send failed: %s', $reason),
            previous: $previous,
        );
    }

    #[NoDiscard]
    public static function driverError(string $driver, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Mail driver "%s" error: %s', $driver, $reason),
            previous: $previous,
        );
    }

    #[NoDiscard]
    public static function invalidRecipient(string $email, string $reason): self
    {
        return new self(
            sprintf('Invalid mail recipient "%s": %s', $email, $reason),
        );
    }

    #[NoDiscard]
    public static function encryptionUnavailable(string $recipient, MailEncryptionPolicy $policy): self
    {
        return new self(
            sprintf(
                'TLS encryption unavailable for recipient "%s" under policy "%s"',
                $recipient,
                $policy->value,
            ),
        );
    }

    #[NoDiscard]
    public static function encryptionFallback(string $recipient, MailEncryptionPolicy $policy): self
    {
        return new self(
            sprintf(
                'TLS encryption fallback for recipient "%s" under policy "%s": sent without encryption',
                $recipient,
                $policy->value,
            ),
        );
    }

    #[NoDiscard]
    public static function transportNotConfigured(string $name): self
    {
        return new self(
            sprintf('Mail transport "%s" is not configured', $name),
        );
    }
}
