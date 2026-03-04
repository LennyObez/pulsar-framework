<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Scheduler\JobContext;

#[CoversClass(JobContext::class)]
final class JobContextTest extends TestCase
{
    #[Test]
    public function constructs_with_required_fields(): void
    {
        $scheduled = new DateTimeImmutable('2026-01-15 10:00:00');
        $started = new DateTimeImmutable('2026-01-15 10:00:01');

        $context = new JobContext(
            scheduledAt: $scheduled,
            startedAt: $started,
        );

        self::assertSame($scheduled, $context->scheduledAt);
        self::assertSame($started, $context->startedAt);
        self::assertNull($context->logger);
        self::assertNull($context->metrics);
        self::assertNull($context->requestContext);
    }

    #[Test]
    public function constructs_with_logger(): void
    {
        $logger = new NullLogger();

        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
            logger: $logger,
        );

        self::assertSame($logger, $context->logger);
    }

    #[Test]
    public function scheduled_at_and_started_at_differ(): void
    {
        $scheduled = new DateTimeImmutable('2026-03-01 00:00:00');
        $started = new DateTimeImmutable('2026-03-01 00:00:05');

        $context = new JobContext(scheduledAt: $scheduled, startedAt: $started);

        self::assertNotSame($context->scheduledAt, $context->startedAt);
        self::assertGreaterThan(
            $context->scheduledAt->getTimestamp(),
            $context->startedAt->getTimestamp(),
        );
    }
}
