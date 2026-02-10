<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Streaming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Streaming\StreamContext;

#[CoversClass(StreamContext::class)]
final class StreamContextTest extends TestCase
{
    #[Test]
    public function startsNotCancelled(): void
    {
        $context = new StreamContext();

        self::assertFalse($context->isCancelled());
    }

    #[Test]
    public function cancelMarksCancelled(): void
    {
        $context = new StreamContext();
        $context->cancel();

        self::assertTrue($context->isCancelled());
    }

    #[Test]
    public function cancelInvokesCallbacks(): void
    {
        $context = new StreamContext();
        $called = false;

        $context->onCancel(static function () use (&$called): void {
            $called = true;
        });

        $context->cancel();

        self::assertTrue($called);
    }

    #[Test]
    public function callbacksInvokedInRegistrationOrder(): void
    {
        $context = new StreamContext();
        $order = [];

        $context->onCancel(static function () use (&$order): void {
            $order[] = 'first';
        });

        $context->onCancel(static function () use (&$order): void {
            $order[] = 'second';
        });

        $context->onCancel(static function () use (&$order): void {
            $order[] = 'third';
        });

        $context->cancel();

        self::assertSame(['first', 'second', 'third'], $order);
    }

    #[Test]
    public function cancelIsIdempotent(): void
    {
        $context = new StreamContext();
        $callCount = 0;

        $context->onCancel(static function () use (&$callCount): void {
            $callCount++;
        });

        $context->cancel();
        $context->cancel();
        $context->cancel();

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function onCancelInvokesImmediatelyWhenAlreadyCancelled(): void
    {
        $context = new StreamContext();
        $context->cancel();

        $called = false;

        $context->onCancel(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }

    #[Test]
    public function noCallbacksRegisteredCancelStillWorks(): void
    {
        $context = new StreamContext();
        $context->cancel();

        self::assertTrue($context->isCancelled());
    }
}
