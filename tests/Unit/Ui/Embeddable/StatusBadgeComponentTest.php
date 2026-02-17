<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\BadgeVariant;
use Pulsar\Ui\Embeddable\StatusBadgeComponent;

#[CoversClass(StatusBadgeComponent::class)]
final class StatusBadgeComponentTest extends TestCase
{
    #[Test]
    public function renders_badge(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Compliant')->variant(BadgeVariant::Success);

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-success', $html);
        self::assertStringContainsString('Compliant', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    #[Test]
    public function tag_name(): void
    {
        self::assertSame('pulsar-status-badge', new StatusBadgeComponent()->tagName());
    }

    #[Test]
    public function warning_variant(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Expiring')->variant(BadgeVariant::Warning);

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-warning', $html);
    }

    #[Test]
    public function error_variant(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Failed')->variant(BadgeVariant::Error);

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-error', $html);
    }

    #[Test]
    public function pulse_animation(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Active')->pulse();

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-pulse', $html);
    }

    #[Test]
    public function no_pulse_by_default(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Static');

        $html = $badge->render();

        self::assertStringNotContainsString('pulsar-badge-pulse', $html);
    }

    #[Test]
    public function with_icon(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('OK')->icon('check');

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-icon', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('check', $html);
    }

    #[Test]
    public function escapes_label(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('<script>alert("xss")</script>');

        $html = $badge->render();

        self::assertStringNotContainsString('<script>', $html);
    }
}
