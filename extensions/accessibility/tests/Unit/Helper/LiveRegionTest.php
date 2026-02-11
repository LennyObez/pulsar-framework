<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Helper\LiveRegion;

final class LiveRegionTest extends TestCase
{
    private LiveRegion $liveRegion;

    protected function setUp(): void
    {
        $this->liveRegion = new LiveRegion();
    }

    #[Test]
    public function polite_has_aria_live_polite(): void
    {
        $html = $this->liveRegion->polite('status-msg');

        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('id="status-msg"', $html);
        self::assertStringContainsString('aria-atomic="true"', $html);
    }

    #[Test]
    public function assertive_has_aria_live_assertive(): void
    {
        $html = $this->liveRegion->assertive('alert-msg');

        self::assertStringContainsString('aria-live="assertive"', $html);
        self::assertStringContainsString('id="alert-msg"', $html);
    }

    #[Test]
    public function status_has_role_status(): void
    {
        $html = $this->liveRegion->status('op-status');

        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('id="op-status"', $html);
    }

    #[Test]
    public function log_has_role_log(): void
    {
        $html = $this->liveRegion->log('activity-log');

        self::assertStringContainsString('role="log"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('aria-relevant="additions"', $html);
    }

    #[Test]
    public function content_is_included_in_output(): void
    {
        $html = $this->liveRegion->polite('msg', 'Hello World');

        self::assertStringContainsString('Hello World', $html);
    }

    #[Test]
    public function id_is_html_escaped(): void
    {
        $html = $this->liveRegion->polite('my"id');

        self::assertStringContainsString('id="my&quot;id"', $html);
    }
}
