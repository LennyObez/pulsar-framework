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

    // ---- Boundary / negative tests ----

    #[Test]
    public function success_result_has_null_error_message(): void
    {
        $result = PublishResult::success('web', 'https://example.com');

        self::assertNull($result->errorMessage);
    }

    #[Test]
    public function failure_result_has_null_external_url(): void
    {
        $result = PublishResult::failure('email', 'SMTP error');

        self::assertNull($result->externalUrl);
    }

    #[Test]
    public function direct_constructor_allows_both_url_and_error(): void
    {
        // Edge case: both fields set via direct constructor (not via factory)
        $result = new PublishResult(
            success: false,
            channelName: 'web',
            externalUrl: 'https://partial.example.com',
            errorMessage: 'Partial failure',
        );

        self::assertFalse($result->success);
        self::assertSame('https://partial.example.com', $result->externalUrl);
        self::assertSame('Partial failure', $result->errorMessage);
    }

    #[Test]
    public function empty_channel_name_is_preserved(): void
    {
        $result = PublishResult::success('');

        self::assertSame('', $result->channelName);
    }

    #[Test]
    public function empty_error_message_is_preserved(): void
    {
        $result = PublishResult::failure('channel', '');

        self::assertSame('', $result->errorMessage);
        self::assertFalse($result->success);
    }
}
