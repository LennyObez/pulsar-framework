<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use RuntimeException;

#[CoversClass(WebhookException::class)]
final class WebhookExceptionExtendedTest extends TestCase
{
    #[Test]
    public function invalidSignatureHasDescriptiveMessage(): void
    {
        $exception = WebhookException::invalidSignature();

        self::assertStringContainsString('signature', mb_strtolower($exception->getMessage()));
        self::assertStringContainsString('verification', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function expiredTimestampContainsAgeAndTolerance(): void
    {
        $exception = WebhookException::expiredTimestamp(600, 300);

        self::assertStringContainsString('600', $exception->getMessage());
        self::assertStringContainsString('300', $exception->getMessage());
        self::assertStringContainsString('old', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function malformedHeaderContainsReason(): void
    {
        $exception = WebhookException::malformedHeader('missing timestamp');

        self::assertStringContainsString('missing timestamp', $exception->getMessage());
        self::assertStringContainsString('header', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function concurrentClaimContainsEventId(): void
    {
        $exception = WebhookException::concurrentClaim('evt-abc-123');

        self::assertStringContainsString('evt-abc-123', $exception->getMessage());
        self::assertStringContainsString('being processed', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function handlerFailedContainsEventIdAndReason(): void
    {
        $exception = WebhookException::handlerFailed('evt-xyz', 'database connection lost');

        self::assertStringContainsString('evt-xyz', $exception->getMessage());
        self::assertStringContainsString('database connection lost', $exception->getMessage());
    }

    #[Test]
    public function allFactoryMethodsReturnWebhookException(): void
    {
        self::assertInstanceOf(WebhookException::class, WebhookException::invalidSignature());
        self::assertInstanceOf(WebhookException::class, WebhookException::expiredTimestamp(1, 1));
        self::assertInstanceOf(WebhookException::class, WebhookException::malformedHeader('test'));
        self::assertInstanceOf(WebhookException::class, WebhookException::concurrentClaim('id'));
        self::assertInstanceOf(WebhookException::class, WebhookException::handlerFailed('id', 'reason'));
    }

    #[Test]
    public function webhookExceptionExtendsRuntimeException(): void
    {
        $exception = WebhookException::invalidSignature();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
