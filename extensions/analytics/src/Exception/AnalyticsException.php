<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for the analytics extension.
 */
#[Api(since: '1.0.0')]
final class AnalyticsException extends RuntimeException
{
    public static function notFound(string $entity, string $id): self
    {
        return new self(sprintf('%s with ID "%s" not found.', $entity, $id));
    }

    public static function invalidConfig(string $message): self
    {
        return new self(sprintf('Invalid analytics configuration: %s', $message));
    }

    public static function rateLimited(): self
    {
        return new self('Rate limit exceeded for analytics collection.');
    }

    public static function siteNotFound(string $trackingId): self
    {
        return new self(sprintf('Analytics site with tracking ID "%s" not found.', $trackingId));
    }

    public static function trackingDisabled(): self
    {
        return new self('Analytics tracking is disabled.');
    }
}
