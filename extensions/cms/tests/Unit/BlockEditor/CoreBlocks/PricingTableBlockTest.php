<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderOutputsPlansWithFeaturesAndCta(): void
    {
        $html = $this->block->render([
            'plans' => [
                [
                    'name' => 'Basic',
                    'price' => '$9/mo',
                    'features' => ['10 users', '1 GB storage'],
                    'ctaText' => 'Sign Up',
                    'ctaUrl' => '/signup?plan=basic',
                ],
            ],
        ]);

        self::assertStringContainsString('pricing-plan__name', $html);
        self::assertStringContainsString('Basic', $html);
        self::assertStringContainsString('$9/mo', $html);
        self::assertStringContainsString('<li>10 users</li>', $html);
        self::assertStringContainsString('Sign Up</a>', $html);
    }

    #[Test]
    public function renderHighlightsPlan(): void
    {
        $html = $this->block->render([
            'plans' => [
                ['name' => 'A', 'price' => '$1', 'features' => [], 'ctaText' => 'Go', 'ctaUrl' => '/'],
                ['name' => 'B', 'price' => '$2', 'features' => [], 'ctaText' => 'Go', 'ctaUrl' => '/'],
            ],
            'highlighted' => 1,
        ]);

        self::assertStringContainsString('pricing-plan--highlighted', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingPlans(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('plans is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingPlanFields(): void
    {
        $errors = $this->block->validate([
            'plans' => [['name' => 'Basic']],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForInvalidHighlightedIndex(): void
    {
        $errors = $this->block->validate([
            'plans' => [
                ['name' => 'A', 'price' => '$1', 'features' => [], 'ctaText' => 'Go', 'ctaUrl' => '/'],
            ],
            'highlighted' => 5,
        ]);

        self::assertStringContainsString('valid plan index', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'plans' => [
                [
                    'name' => 'Pro',
                    'price' => '$29/mo',
                    'features' => ['Unlimited'],
                    'ctaText' => 'Upgrade',
                    'ctaUrl' => '/upgrade',
                ],
            ],
        ]));
    }
}
