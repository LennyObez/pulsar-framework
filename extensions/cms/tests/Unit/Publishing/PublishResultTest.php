<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Publishing\PublishResult;

#[CoversClass(PublishResult::class)]
final class PublishResultTest extends TestCase
{
    #[Test]
    public function success_creates_successful_result(): void
    {
        $result = PublishResult::success('web', 'https://example.com/page');

        self::assertTrue($result->success);
        self::assertSame('web', $result->channelName);
        self::assertSame('https://example.com/page', $result->externalUrl);
        self::assertNull($result->errorMessage);
    }

    #[Test]
    public function success_without_external_url(): void
    {
        $result = PublishResult::success('rss');

        self::assertTrue($result->success);
        self::assertSame('rss', $result->channelName);
        self::assertNull($result->externalUrl);
    }

    #[Test]
    public function failure_creates_failed_result(): void
    {
        $result = PublishResult::failure('static', 'Disk full');

        self::assertFalse($result->success);
        self::assertSame('static', $result->channelName);
        self::assertSame('Disk full', $result->errorMessage);
        self::assertNull($result->externalUrl);
    }

    #[Test]
    public function constructor_allows_all_combinations(): void
    {
        $result = new PublishResult(
            success: true,
            channelName: 'custom',
            externalUrl: 'https://cdn.example.com',
            errorMessage: null,
        );

        self::assertTrue($result->success);
        self::assertSame('custom', $result->channelName);
    }
}
