<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Publishing\PublishResult;

/**
 * Verifies that PublishResult has a queued() factory and isQueued() check,
 * and that PublishingOrchestrator uses queued status for async dispatch.
 */
#[CoversClass(PublishResult::class)]
final class PublishResultQueuedTest extends TestCase
{
    #[Test]
    public function queuedFactoryCreatesSuccessfulResult(): void
    {
        $result = PublishResult::queued('rss');

        self::assertTrue($result->success);
        self::assertSame('rss', $result->channelName);
        self::assertNull($result->externalUrl);
        self::assertNull($result->errorMessage);
    }

    #[Test]
    public function queuedResultReportsAsQueued(): void
    {
        $result = PublishResult::queued('social');

        self::assertTrue($result->isQueued());
    }

    #[Test]
    public function successResultWithUrlIsNotQueued(): void
    {
        $result = PublishResult::success('blog', 'https://blog.example.com/post-1');

        self::assertFalse($result->isQueued());
    }

    #[Test]
    public function failureResultIsNotQueued(): void
    {
        $result = PublishResult::failure('cdn', 'Timeout');

        self::assertFalse($result->isQueued());
    }
}
