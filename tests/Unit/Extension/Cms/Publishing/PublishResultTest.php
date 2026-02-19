<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Publishing\PublishResult;

#[CoversClass(PublishResult::class)]
final class PublishResultTest extends TestCase
{
    #[Test]
    public function success_factory(): void
    {
        $result = PublishResult::success('web', 'https://example.com/hello');

        self::assertTrue($result->success);
        self::assertSame('web', $result->channelName);
        self::assertSame('https://example.com/hello', $result->externalUrl);
        self::assertNull($result->errorMessage);
    }

    #[Test]
    public function success_factory_without_url(): void
    {
        $result = PublishResult::success('web');

        self::assertTrue($result->success);
        self::assertNull($result->externalUrl);
    }

    #[Test]
    public function failure_factory(): void
    {
        $result = PublishResult::failure('rss', 'Feed generation failed');

        self::assertFalse($result->success);
        self::assertSame('rss', $result->channelName);
        self::assertSame('Feed generation failed', $result->errorMessage);
        self::assertNull($result->externalUrl);
    }
}
