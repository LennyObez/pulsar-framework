<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use RuntimeException;

#[CoversClass(AnalyticsException::class)]
final class AnalyticsExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        self::assertInstanceOf(RuntimeException::class, new AnalyticsException('test'));
    }

    #[Test]
    public function notFoundFactory(): void
    {
        $exception = AnalyticsException::notFound('Site', 'abc-123');

        self::assertSame('Site with ID "abc-123" not found.', $exception->getMessage());
    }

    #[Test]
    public function invalidConfigFactory(): void
    {
        $exception = AnalyticsException::invalidConfig('missing tracking ID');

        self::assertSame('Invalid analytics configuration: missing tracking ID', $exception->getMessage());
    }

    #[Test]
    public function rateLimitedFactory(): void
    {
        $exception = AnalyticsException::rateLimited();

        self::assertSame('Rate limit exceeded for analytics collection.', $exception->getMessage());
    }

    #[Test]
    public function siteNotFoundFactory(): void
    {
        $exception = AnalyticsException::siteNotFound('plsr_abc123');

        self::assertSame('Analytics site with tracking ID "plsr_abc123" not found.', $exception->getMessage());
    }

    #[Test]
    public function trackingDisabledFactory(): void
    {
        $exception = AnalyticsException::trackingDisabled();

        self::assertSame('Analytics tracking is disabled.', $exception->getMessage());
    }
}
