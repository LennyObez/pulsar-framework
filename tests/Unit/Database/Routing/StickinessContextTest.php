<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function test_initially_not_sticky(): void
    {
        self::assertFalse($this->context->shouldUsePrimary());
    }

    public function test_mark_write_activates_stickiness(): void
    {
        $this->context->markWrite();

        self::assertTrue($this->context->shouldUsePrimary());
    }

    public function test_request_scoped_stickiness_persists_until_reset(): void
    {
        $this->context->markWrite('request');

        self::assertTrue($this->context->shouldUsePrimary());

        // Still sticky after time passes
        usleep(5000);
        self::assertTrue($this->context->shouldUsePrimary());
    }

    public function test_timed_stickiness_expires(): void
    {
        // Pin for 1ms
        $this->context->markWrite(1);

        self::assertTrue($this->context->shouldUsePrimary());

        // Wait for expiration
        usleep(2000); // 2ms

        self::assertFalse($this->context->shouldUsePrimary());
    }

    public function test_reset_clears_state(): void
    {
        $this->context->markWrite();

        self::assertTrue($this->context->shouldUsePrimary());

        $this->context->reset();

        self::assertFalse($this->context->shouldUsePrimary());
    }

    public function test_timed_stickiness_with_large_duration_persists(): void
    {
        // Pin for 10 seconds
        $this->context->markWrite(10_000);

        self::assertTrue($this->context->shouldUsePrimary());

        // Still sticky after short wait
        usleep(1000);
        self::assertTrue($this->context->shouldUsePrimary());
    }

    public function test_reset_clears_timed_stickiness(): void
    {
        $this->context->markWrite(10_000);

        self::assertTrue($this->context->shouldUsePrimary());

        $this->context->reset();

        self::assertFalse($this->context->shouldUsePrimary());
    }
}
