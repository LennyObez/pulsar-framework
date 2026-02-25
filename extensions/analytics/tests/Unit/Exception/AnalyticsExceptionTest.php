<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use RuntimeException;

final class AnalyticsExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new AnalyticsException('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function notFoundIncludesEntityAndId(): void
    {
        $exception = AnalyticsException::notFound('Goal', 'goal-123');

        self::assertSame('Goal with ID "goal-123" not found.', $exception->getMessage());
    }

    #[Test]
    public function invalidConfigIncludesMessage(): void
    {
        $exception = AnalyticsException::invalidConfig('retention days must be positive');

        self::assertSame('Invalid analytics configuration: retention days must be positive', $exception->getMessage());
    }

    #[Test]
    public function rateLimitedHasFixedMessage(): void
    {
        $exception = AnalyticsException::rateLimited();

        self::assertSame('Rate limit exceeded for analytics collection.', $exception->getMessage());
    }

    #[Test]
    public function siteNotFoundIncludesTrackingId(): void
    {
        $exception = AnalyticsException::siteNotFound('plsr_abc12345');

        self::assertSame('Analytics site with tracking ID "plsr_abc12345" not found.', $exception->getMessage());
    }

    #[Test]
    public function trackingDisabledHasFixedMessage(): void
    {
        $exception = AnalyticsException::trackingDisabled();

        self::assertSame('Analytics tracking is disabled.', $exception->getMessage());
    }
}
