<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RouteContext;

#[CoversClass(RouteContext::class)]
final class RouteContextTest extends TestCase
{
    #[Test]
    public function labelReturnsNameWhenSet(): void
    {
        $ctx = new RouteContext();
        $ctx->setName('users.show');
        $ctx->setPattern('/users/{id}');

        self::assertSame('users.show', $ctx->label());
    }

    #[Test]
    public function labelReturnsPatternWhenNameIsNull(): void
    {
        $ctx = new RouteContext();
        $ctx->setPattern('/users/{id}');

        self::assertSame('/users/{id}', $ctx->label());
    }

    #[Test]
    public function labelReturnsUnmatchedWhenBothNull(): void
    {
        $ctx = new RouteContext();

        self::assertSame('unmatched', $ctx->label());
    }

    #[Test]
    public function resetClearsBothFields(): void
    {
        $ctx = new RouteContext();
        $ctx->setName('users.show');
        $ctx->setPattern('/users/{id}');

        $ctx->reset();

        self::assertNull($ctx->pattern());
        self::assertNull($ctx->name());
        self::assertSame('unmatched', $ctx->label());
    }

    #[Test]
    public function fibersDoNotShareRouteSlot(): void
    {
        $ctx = new RouteContext();
        $ctx->setName('root.route');
        $ctx->setPattern('/root');

        $observed = null;
        $fiber = new Fiber(function () use ($ctx, &$observed): void {
            $observed = ['initial' => $ctx->label()];
            $ctx->setName('fiber.route');
            $ctx->setPattern('/fiber');
            $observed['fiber_label'] = $ctx->label();
        });

        $fiber->start();

        self::assertSame('root.route', $ctx->label());
        self::assertSame('unmatched', $observed['initial']);
        self::assertSame('fiber.route', $observed['fiber_label']);
    }

    #[Test]
    public function resetOnlyAffectsCurrentFiber(): void
    {
        $ctx = new RouteContext();
        $ctx->setName('root.route');

        $fiber = new Fiber(function () use ($ctx): void {
            $ctx->setName('fiber.route');
            $ctx->reset();
        });

        $fiber->start();

        self::assertSame('root.route', $ctx->label());
    }

    #[Test]
    public function twoFibersSeeIndependentSlots(): void
    {
        $ctx = new RouteContext();

        $fiberA = new Fiber(function () use ($ctx): mixed {
            $ctx->setName('a.route');
            Fiber::suspend();

            return $ctx->label();
        });

        $fiberB = new Fiber(function () use ($ctx): mixed {
            $ctx->setName('b.route');
            Fiber::suspend();

            return $ctx->label();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();
        $fiberB->resume();

        self::assertSame('a.route', $fiberA->getReturn());
        self::assertSame('b.route', $fiberB->getReturn());
    }
}
