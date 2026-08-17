<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Routing\StickinessContext;

#[CoversClass(StickinessContext::class)]
final class StickinessContextTest extends TestCase
{
    private StickinessContext $context;

    protected function setUp(): void
    {
        $this->context = new StickinessContext();
    }

    #[Test]
    public function initiallyNotSticky(): void
    {
        self::assertFalse($this->context->shouldUsePrimary());
    }

    #[Test]
    public function markWriteActivatesStickiness(): void
    {
        $this->context->markWrite();

        self::assertTrue($this->context->shouldUsePrimary());
    }

    #[Test]
    public function requestScopedStickinessPersistsUntilReset(): void
    {
        $this->context->markWrite('request');

        self::assertTrue($this->context->shouldUsePrimary());

        // Still sticky after time passes
        usleep(5000);
        self::assertTrue($this->context->shouldUsePrimary());
    }

    #[Test]
    public function timedStickinessExpires(): void
    {
        // Well above the ~15.6ms Windows timer granularity, where 1ms and 2ms could
        // not be ordered.
        $this->context->markWrite(25);

        self::assertTrue($this->context->shouldUsePrimary());

        usleep(100_000); // 100ms

        self::assertFalse($this->context->shouldUsePrimary());
    }

    #[Test]
    public function resetClearsState(): void
    {
        $this->context->markWrite();

        self::assertTrue($this->context->shouldUsePrimary());

        $this->context->reset();

        self::assertFalse($this->context->shouldUsePrimary());
    }

    #[Test]
    public function timedStickinessWithLargeDurationPersists(): void
    {
        // Pin for 10 seconds
        $this->context->markWrite(10_000);

        self::assertTrue($this->context->shouldUsePrimary());

        // Still sticky after short wait
        usleep(1000);
        self::assertTrue($this->context->shouldUsePrimary());
    }

    #[Test]
    public function resetClearsTimedStickiness(): void
    {
        $this->context->markWrite(10_000);

        self::assertTrue($this->context->shouldUsePrimary());

        $this->context->reset();

        self::assertFalse($this->context->shouldUsePrimary());
    }

    #[Test]
    public function fiberWriteDoesNotPinRoot(): void
    {
        $fiber = new Fiber(function (): void {
            $this->context->markWrite('request');
        });

        $fiber->start();

        // Root must NOT be pinned to primary because of the Fiber's write.
        self::assertFalse($this->context->shouldUsePrimary());
    }

    #[Test]
    public function rootWriteDoesNotPinFiber(): void
    {
        $this->context->markWrite('request');

        $observed = null;
        $fiber = new Fiber(function () use (&$observed): void {
            $observed = $this->context->shouldUsePrimary();
        });

        $fiber->start();

        self::assertFalse($observed);
        self::assertTrue($this->context->shouldUsePrimary());
    }

    #[Test]
    public function fiberResetDoesNotClearRoot(): void
    {
        $this->context->markWrite('request');

        $fiber = new Fiber(function (): void {
            $this->context->markWrite('request');
            $this->context->reset();
        });

        $fiber->start();

        self::assertTrue($this->context->shouldUsePrimary());
    }
}
