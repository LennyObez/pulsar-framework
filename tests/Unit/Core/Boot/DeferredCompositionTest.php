<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Boot\DeferredComposition;
use stdClass;

#[CoversClass(DeferredComposition::class)]
final class DeferredCompositionTest extends TestCase
{
    #[Test]
    public function runsTheCallbackWhenTheBindingIsPresent(): void
    {
        $container = new Container();
        $container->instance(DeferredPort::class, new DeferredPort());

        $ran = new DeferredFlag();
        $composition = new DeferredComposition();
        $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($ran): void {
            $ran->value = true;
        });

        $composition->apply($container);

        self::assertTrue($ran->value);
    }

    /**
     * Silent absence is the whole point: an optional capability that nothing
     * registered must cost one has() call and leave no trace — no null-object
     * middleware, no per-request check, no exception.
     */
    #[Test]
    public function doesNothingWhenTheBindingIsAbsent(): void
    {
        $ran = new DeferredFlag();
        $composition = new DeferredComposition();
        $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($ran): void {
            $ran->value = true;
        });

        $composition->apply(new Container());

        self::assertFalse($ran->value);
    }

    #[Test]
    public function handsTheContainerToTheCallback(): void
    {
        $container = new Container();
        $port = new DeferredPort();
        $container->instance(DeferredPort::class, $port);

        $seen = new DeferredCapture();
        $composition = new DeferredComposition();
        $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($seen): void {
            $seen->container = $c;
        });

        $composition->apply($container);

        self::assertSame($container, $seen->container);
    }

    #[Test]
    public function runsEveryPendingCallbackInRegistrationOrder(): void
    {
        $container = new Container();
        $container->instance(DeferredPort::class, new DeferredPort());
        $container->instance(stdClass::class, new stdClass());

        $log = new DeferredLog();
        $composition = new DeferredComposition();
        $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($log): void {
            $log->entries[] = 'first';
        });
        $composition->whenBound(stdClass::class, static function (ContainerInterface $c) use ($log): void {
            $log->entries[] = 'second';
        });

        $composition->apply($container);

        self::assertSame(['first', 'second'], $log->entries);
    }

    #[Test]
    public function anAbsentBindingDoesNotBlockTheRestOfTheQueue(): void
    {
        $container = new Container();
        $container->instance(stdClass::class, new stdClass());

        $log = new DeferredLog();
        $composition = new DeferredComposition();
        $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($log): void {
            $log->entries[] = 'absent';
        });
        $composition->whenBound(stdClass::class, static function (ContainerInterface $c) use ($log): void {
            $log->entries[] = 'present';
        });

        $composition->apply($container);

        self::assertSame(['present'], $log->entries);
    }

    /**
     * apply() drains rather than marks done. A second boot in the same process
     * re-runs every wiring, which re-registers its callback; if the queue were
     * merely flagged, the re-registered callbacks would run twice — and if it
     * were never cleared, the previous boot's closures would run again against
     * a rebuilt container.
     */
    #[Test]
    public function applyDrainsTheQueueSoASecondApplyIsANoOp(): void
    {
        $container = new Container();
        $container->instance(DeferredPort::class, new DeferredPort());

        $log = new DeferredLog();
        $composition = new DeferredComposition();
        $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($log): void {
            $log->entries[] = 'run';
        });

        $composition->apply($container);
        $composition->apply($container);

        self::assertSame(['run'], $log->entries);
    }

    #[Test]
    public function aReRegisteredCallbackRunsAgainOnTheNextApply(): void
    {
        $container = new Container();
        $container->instance(DeferredPort::class, new DeferredPort());

        $log = new DeferredLog();
        $composition = new DeferredComposition();
        $register = static function () use ($composition, $log): void {
            $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($log): void {
                $log->entries[] = 'boot';
            });
        };

        $register();
        $composition->apply($container);

        $register();
        $composition->apply($container);

        self::assertSame(['boot', 'boot'], $log->entries);
    }

    #[Test]
    public function applyOnAnEmptyQueueLeavesTheSeamUsable(): void
    {
        $container = new Container();
        $container->instance(DeferredPort::class, new DeferredPort());

        $log = new DeferredLog();
        $composition = new DeferredComposition();
        $composition->apply($container);

        $composition->whenBound(DeferredPort::class, static function (ContainerInterface $c) use ($log): void {
            $log->entries[] = 'after-empty-drain';
        });
        $composition->apply($container);

        self::assertSame(['after-empty-drain'], $log->entries);
    }
}

final class DeferredPort {}

final class DeferredFlag
{
    public bool $value = false;
}

final class DeferredCapture
{
    public ?ContainerInterface $container = null;
}

final class DeferredLog
{
    /** @var list<string> */
    public array $entries = [];
}
