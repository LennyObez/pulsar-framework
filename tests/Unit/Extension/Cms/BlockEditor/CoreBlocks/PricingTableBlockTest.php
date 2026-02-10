<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\PricingTableBlock;

#[CoversClass(PricingTableBlock::class)]
final class PricingTableBlockTest extends TestCase
{
    private PricingTableBlock $block;

    protected function setUp(): void
    {
        $this->block = new PricingTableBlock();
    }

    #[Test]
    public function typeReturnsPricingTable(): void
    {
        self::assertSame('pricing-table', $this->block->type());
    }

    #[Test]
    public function rendersBasicPricingTable(): void
    {
        $html = $this->block->render([
            'plans' => [
                [
                    'name' => 'Basic',
                    'price' => '$9/mo',
                    'features' => ['1 User', '10GB Storage'],
                    'ctaText' => 'Choose Basic',
                    'ctaUrl' => '/signup?plan=basic',
                ],
            ],
        ]);

        self::assertStringContainsString('<div class="pricing-table">', $html);
        self::assertStringContainsString('<div class="pricing-plan">', $html);
        self::assertStringContainsString('<h3 class="pricing-plan__name">Basic</h3>', $html);
        self::assertStringContainsString('<div class="pricing-plan__price">$9/mo</div>', $html);
        self::assertStringContainsString('<li>1 User</li>', $html);
        self::assertStringContainsString('<li>10GB Storage</li>', $html);
        self::assertStringContainsString('<a href="/signup?plan=basic" class="pricing-plan__cta">Choose Basic</a>', $html);
    }

    #[Test]
    public function rendersHighlightedPlan(): void
    {
        $html = $this->block->render([
            'plans' => [
                [
                    'name' => 'Basic',
                    'price' => '$9/mo',
                    'features' => ['1 User'],
                    'ctaText' => 'Choose',
                    'ctaUrl' => '/basic',
                ],
                [
                    'name' => 'Pro',
                    'price' => '$29/mo',
                    'features' => ['5 Users'],
                    'ctaText' => 'Choose',
                    'ctaUrl' => '/pro',
                ],
            ],
            'highlighted' => 1,
        ]);

        self::assertStringContainsString('<div class="pricing-plan">', $html);
        self::assertStringContainsString('<div class="pricing-plan pricing-plan--highlighted">', $html);
    }

    #[Test]
    public function rendersFeaturesListForEachPlan(): void
    {
        $html = $this->block->render([
            'plans' => [
                [
                    'name' => 'Enterprise',
                    'price' => '$99/mo',
                    'features' => ['Unlimited Users', 'Priority Support', 'Custom Domain'],
                    'ctaText' => 'Contact Us',
                    'ctaUrl' => '/enterprise',
                ],
            ],
        ]);

        self::assertStringContainsString('<ul class="pricing-plan__features">', $html);
        self::assertStringContainsString('<li>Unlimited Users</li>', $html);
        self::assertStringContainsString('<li>Priority Support</li>', $html);
        self::assertStringContainsString('<li>Custom Domain</li>', $html);
    }

    #[Test]
    public function escapesXssInPlanName(): void
    {
        $html = $this->block->render([
            'plans' => [
                [
                    'name' => '<script>xss</script>',
                    'price' => '$0',
                    'features' => [],
                    'ctaText' => 'Go',
                    'ctaUrl' => '/',
                ],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;xss&lt;/script&gt;', $html);
    }

    #[Test]
    public function escapesXssInCtaUrl(): void
    {
        $html = $this->block->render([
            'plans' => [
                [
                    'name' => 'Plan',
                    'price' => '$0',
                    'features' => [],
                    'ctaText' => 'Go',
                    'ctaUrl' => '" onclick="alert(1)',
                ],
            ],
        ]);

        self::assertStringContainsString('href="&quot; onclick=&quot;alert(1)', $html);
    }

    #[Test]
    public function escapesXssInFeatures(): void
    {
        $html = $this->block->render([
            'plans' => [
                [
                    'name' => 'Plan',
                    'price' => '$0',
                    'features' => ['<img src=x onerror=alert(1)>'],
                    'ctaText' => 'Go',
                    'ctaUrl' => '/',
                ],
            ],
        ]);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    #[Test]
    public function validatesRequiredPlans(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('plans is required and must be an array', $errors);
    }

    #[Test]
    public function validatesPlansNotArray(): void
    {
        $errors = $this->block->validate(['plans' => 'invalid']);

        self::assertContains('plans is required and must be an array', $errors);
    }

    #[Test]
    public function validatesPlanMissingName(): void
    {
        $errors = $this->block->validate([
            'plans' => [
                [
                    'price' => '$9/mo',
                    'features' => ['Feature'],
                    'ctaText' => 'Go',
                    'ctaUrl' => '/',
                ],
            ],
        ]);

        self::assertContains('plans[0].name is required and must be a string', $errors);
    }

    #[Test]
    public function validatesPlanMissingFeatures(): void
    {
        $errors = $this->block->validate([
            'plans' => [
                [
                    'name' => 'Basic',
                    'price' => '$9/mo',
                    'ctaText' => 'Go',
                    'ctaUrl' => '/',
                ],
            ],
        ]);

        self::assertContains('plans[0].features is required and must be an array', $errors);
    }

    #[Test]
    public function validatesHighlightedOutOfRange(): void
    {
        $errors = $this->block->validate([
            'plans' => [
                [
                    'name' => 'Basic',
                    'price' => '$9/mo',
                    'features' => [],
                    'ctaText' => 'Go',
                    'ctaUrl' => '/',
                ],
            ],
            'highlighted' => 5,
        ]);

        self::assertContains('highlighted must be a valid plan index', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'plans' => [
                [
                    'name' => 'Basic',
                    'price' => '$9/mo',
                    'features' => ['Feature A'],
                    'ctaText' => 'Choose Basic',
                    'ctaUrl' => '/basic',
                ],
            ],
            'highlighted' => 0,
        ]);

        self::assertSame([], $errors);
    }
}
