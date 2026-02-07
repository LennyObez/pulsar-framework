<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Exception;

use NoDiscard;
use RuntimeException;
use Throwable;

/**
 * Provider-level payment exceptions.
 */
final class PaymentProviderException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorType,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[NoDiscard]
    public static function declined(string $reason): self
    {
        return new self(
            \sprintf('Payment declined: %s', $reason),
            'declined',
        );
    }

    #[NoDiscard]
    public static function timeout(string $message = 'Provider request timed out'): self
    {
        return new self($message, 'timeout');
    }

    #[NoDiscard]
    public static function networkError(string $message = 'Provider network error'): self
    {
        return new self($message, 'network_error');
    }

    #[NoDiscard]
    public static function rateLimited(string $message = 'Provider rate limit exceeded'): self
    {
        return new self($message, 'rate_limited');
    }

    #[NoDiscard]
    public static function providerError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 'provider_error', $previous);
    }

    #[NoDiscard]
    public static function refundFailed(string $reason): self
    {
        return new self(
            \sprintf('Refund failed: %s', $reason),
            'refund_failed',
        );
    }
}
