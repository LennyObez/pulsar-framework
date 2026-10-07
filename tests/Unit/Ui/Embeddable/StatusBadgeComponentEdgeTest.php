<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\BadgeVariant;
use Pulsar\Ui\Embeddable\StatusBadgeComponent;

/**
 * Edge case tests for StatusBadgeComponent.
 */
#[CoversClass(StatusBadgeComponent::class)]
final class StatusBadgeComponentEdgeTest extends TestCase
{
    #[Test]
    public function tagNameReturnsPulsarStatusBadge(): void
    {
        $badge = new StatusBadgeComponent();

        self::assertSame('pulsar-status-badge', $badge->tagName());
    }

    #[Test]
    public function renderWithDefaultVariant(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Active');

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-info', $html);
        self::assertStringContainsString('Active', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    /**
     * @return array<string, array{BadgeVariant, string}>
     */
    public static function variantProvider(): array
    {
        return [
            'success' => [BadgeVariant::Success, 'pulsar-badge-success'],
            'warning' => [BadgeVariant::Warning, 'pulsar-badge-warning'],
            'error' => [BadgeVariant::Error, 'pulsar-badge-error'],
            'info' => [BadgeVariant::Info, 'pulsar-badge-info'],
            'neutral' => [BadgeVariant::Neutral, 'pulsar-badge-neutral'],
        ];
    }

    #[Test]
    #[DataProvider('variantProvider')]
    public function renderWithVariant(BadgeVariant $variant, string $expectedClass): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Test');
        $badge->variant($variant);

        $html = $badge->render();

        self::assertStringContainsString($expectedClass, $html);
    }

    #[Test]
    public function renderWithIcon(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('OK');
        $badge->icon('check-circle');

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-icon', $html);
        self::assertStringContainsString('check-circle', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    #[Test]
    public function renderWithoutIcon(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Plain');

        $html = $badge->render();

        self::assertStringNotContainsString('pulsar-badge-icon', $html);
    }

    #[Test]
    public function renderWithPulseEffect(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Live');
        $badge->pulse(true);

        $html = $badge->render();

        self::assertStringContainsString('pulsar-badge-pulse', $html);
    }

    #[Test]
    public function renderWithoutPulseEffect(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('Static');
        $badge->pulse(false);

        $html = $badge->render();

        self::assertStringNotContainsString('pulsar-badge-pulse', $html);
    }

    #[Test]
    public function renderEscapesLabelHtml(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('<script>bad</script>');

        $html = $badge->render();

        self::assertStringNotContainsString('<script>bad</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderEscapesIconHtml(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('X');
        $badge->icon('<img onerror=alert(1)>');

        $html = $badge->render();

        self::assertStringNotContainsString('<img onerror', $html);
    }

    #[Test]
    public function badgeVariantValues(): void
    {
        self::assertSame('success', BadgeVariant::Success->value);
        self::assertSame('warning', BadgeVariant::Warning->value);
        self::assertSame('error', BadgeVariant::Error->value);
        self::assertSame('info', BadgeVariant::Info->value);
        self::assertSame('neutral', BadgeVariant::Neutral->value);
    }

    #[Test]
    public function badgeVariantFromString(): void
    {
        $variant = BadgeVariant::from('error');

        self::assertSame(BadgeVariant::Error, $variant);
    }

    #[Test]
    public function badgeVariantTryFromReturnsNullForInvalid(): void
    {
        $variant = BadgeVariant::tryFrom('invalid');

        self::assertNull($variant);
    }

    #[Test]
    public function renderWrapsInCustomElement(): void
    {
        $badge = new StatusBadgeComponent();
        $badge->label('X');

        $html = $badge->render();

        self::assertStringStartsWith('<pulsar-status-badge', $html);
        self::assertStringEndsWith('</pulsar-status-badge>', $html);
    }
}
