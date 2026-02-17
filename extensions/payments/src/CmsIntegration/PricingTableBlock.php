<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Pulsar\Api\Api;

/**
 * CMS block: Pricing comparison table.
 */
#[Api(since: '1.0.0')]
final readonly class PricingTableBlock
{
    /**
     * @param list<PricingTablePlan> $plans
     */
    public function __construct(
        public array $plans,
        public string $billingToggle,
        public ?string $highlightedPlanId,
    ) {}

    /**
     * Render the pricing table HTML.
     */
    public function render(): string
    {
        $columns = '';

        foreach ($this->plans as $plan) {
            $highlighted = $plan->id === $this->highlightedPlanId ? ' pulsar-pricing-highlighted' : '';
            $badge = $plan->id === $this->highlightedPlanId ? '<span class="pulsar-badge pulsar-badge-primary">Most Popular</span>' : '';
            $name = htmlspecialchars($plan->name, ENT_QUOTES | ENT_HTML5);
            $price = htmlspecialchars($plan->priceDisplay, ENT_QUOTES | ENT_HTML5);

            $features = '';

            foreach ($plan->features as $feature) {
                $featureText = htmlspecialchars($feature, ENT_QUOTES | ENT_HTML5);
                $features .= "<li>$featureText</li>\n";
            }

            $ctaText = htmlspecialchars($plan->ctaText, ENT_QUOTES | ENT_HTML5);

            $columns .= <<<HTML
                <div class="pulsar-pricing-plan{$highlighted}">
                    {$badge}
                    <h3 class="pulsar-pricing-name">{$name}</h3>
                    <div class="pulsar-pricing-price">{$price}</div>
                    <ul class="pulsar-pricing-features">{$features}</ul>
                    <a href="{$plan->ctaUrl}" class="pulsar-btn pulsar-btn-primary">{$ctaText}</a>
                </div>
                HTML;
        }

        return <<<HTML
            <div class="pulsar-pricing-table">{$columns}</div>
            HTML;
    }
}
