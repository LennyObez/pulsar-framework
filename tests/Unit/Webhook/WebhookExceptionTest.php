<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use RuntimeException;

#[CoversClass(WebhookException::class)]
final class WebhookExceptionTest extends TestCase
{
    #[Test]
    public function invalidSignatureReturnsExpectedMessage(): void
    {
        $exception = WebhookException::invalidSignature();

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('signature verification failed', $exception->getMessage());
    }

    #[Test]
    public function expiredTimestampContainsAgeAndTolerance(): void
    {
        $exception = WebhookException::expiredTimestamp(600, 300);

        self::assertStringContainsString('600', $exception->getMessage());
        self::assertStringContainsString('300', $exception->getMessage());
        self::assertStringContainsString('too old', $exception->getMessage());
    }

    #[Test]
    public function malformedHeaderContainsReason(): void
    {
        $exception = WebhookException::malformedHeader('missing timestamp');

        self::assertStringContainsString('missing timestamp', $exception->getMessage());
        self::assertStringContainsString('Malformed', $exception->getMessage());
    }

    #[Test]
    public function concurrentClaimContainsEventId(): void
    {
        $exception = WebhookException::concurrentClaim('evt-999');

        self::assertStringContainsString('evt-999', $exception->getMessage());
        self::assertStringContainsString('currently being processed', $exception->getMessage());
    }

    #[Test]
    public function handlerFailedContainsEventIdAndReason(): void
    {
        $exception = WebhookException::handlerFailed('evt-abc', 'timeout');

        self::assertStringContainsString('evt-abc', $exception->getMessage());
        self::assertStringContainsString('timeout', $exception->getMessage());
        self::assertStringContainsString('handler failed', strtolower($exception->getMessage()));
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = WebhookException::invalidSignature();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
