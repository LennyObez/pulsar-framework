<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Streaming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Streaming\StreamContext;
use stdClass;

#[CoversClass(StreamContext::class)]
final class StreamContextTest extends TestCase
{
    #[Test]
    public function startsNotCancelled(): void
    {
        $ctx = new StreamContext();

        self::assertFalse($ctx->isCancelled());
    }

    #[Test]
    public function cancelSetsCancelledState(): void
    {
        $ctx = new StreamContext();
        $ctx->cancel();

        self::assertTrue($ctx->isCancelled());
    }

    #[Test]
    public function cancelInvokesCallbacksInOrder(): void
    {
        $ctx = new StreamContext();
        $order = [];

        $ctx->onCancel(static function () use (&$order): void {
            $order[] = 'first';
        });

        $ctx->onCancel(static function () use (&$order): void {
            $order[] = 'second';
        });

        $ctx->cancel();

        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function cancelIsIdempotent(): void
    {
        $ctx = new StreamContext();
        $count = 0;

        $ctx->onCancel(static function () use (&$count): void {
            $count++;
        });

        $ctx->cancel();
        $ctx->cancel();

        self::assertSame(1, $count);
    }

    #[Test]
    public function onCancelInvokesImmediatelyIfAlreadyCancelled(): void
    {
        $ctx = new StreamContext();
        $ctx->cancel();

        $called = false;
        $ctx->onCancel(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }

    #[Test]
    public function onCancelRegistersWhenNotCancelled(): void
    {
        $ctx = new StreamContext();
        $state = new stdClass();
        $state->called = false;

        $ctx->onCancel(static function () use ($state): void {
            $state->called = true;
        });

        self::assertFalse($state->called);

        $ctx->cancel();

        self::assertTrue($state->called);
    }
}
