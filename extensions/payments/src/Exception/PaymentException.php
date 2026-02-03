<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Exception;

use NoDiscard;
use RuntimeException;

use function sprintf;

/**
 * Domain-level payment exceptions.
 */
final class PaymentException extends RuntimeException
{
    #[NoDiscard]
    public static function invalidTransition(string $entity, string $from, string $to): self
    {
        return new self(sprintf(
            'Invalid %s transition from "%s" to "%s"',
            $entity,
            $from,
            $to,
        ));
    }

    #[NoDiscard]
    public static function notFound(string $entity, string $id): self
    {
        return new self(sprintf('%s not found: %s', $entity, $id));
    }

    #[NoDiscard]
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
