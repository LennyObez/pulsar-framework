<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use Override;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Embeddable status badge component for compliance/health indicators.
 *
 * Renders a colored badge with status text and optional icon.
 * Works as <pulsar-status-badge> custom element.
 */
#[Api(since: '1.0.0')]
final class StatusBadgeComponent extends EmbeddableComponent
{
    private string $label = '';
    private BadgeVariant $variant = BadgeVariant::Info;
    private ?string $icon = null;
    private bool $pulse = false;

    #[Override]
    public function tagName(): string
    {
        return 'pulsar-status-badge';
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function variant(BadgeVariant $variant): self
    {
        $this->variant = $variant;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function pulse(bool $pulse = true): self
    {
        $this->pulse = $pulse;

        return $this;
    }

    #[Override]
    public function renderInner(): string
    {
        $escapedLabel = htmlspecialchars($this->label, ENT_QUOTES, 'UTF-8');
        $variantClass = 'pulsar-badge-' . $this->variant->value;
        $pulseClass = $this->pulse ? ' pulsar-badge-pulse' : '';

        $iconHtml = '';
        if ($this->icon !== null) {
            $iconHtml = sprintf(
                '<span class="pulsar-badge-icon" aria-hidden="true">%s</span>',
                htmlspecialchars($this->icon, ENT_QUOTES, 'UTF-8'),
            );
        }

        return sprintf(
            '<span class="pulsar-badge %s%s" role="status">%s<span class="pulsar-badge-label">%s</span></span>',
            $variantClass,
            $pulseClass,
            $iconHtml,
            $escapedLabel,
        );
    }
}
