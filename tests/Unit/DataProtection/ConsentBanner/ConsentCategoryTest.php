<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\ConsentBanner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentBanner\ConsentCategory;

#[CoversClass(ConsentCategory::class)]
final class ConsentCategoryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $category = new ConsentCategory(
            key: 'analytics',
            label: 'Analytics',
            description: 'Tracking cookies for usage statistics',
            required: false,
            defaultEnabled: true,
        );

        self::assertSame('analytics', $category->key);
        self::assertSame('Analytics', $category->label);
        self::assertSame('Tracking cookies for usage statistics', $category->description);
        self::assertFalse($category->required);
        self::assertTrue($category->defaultEnabled);
    }

    #[Test]
    public function constructorDefaultsRequiredAndDefaultEnabledToFalse(): void
    {
        $category = new ConsentCategory(
            key: 'marketing',
            label: 'Marketing',
            description: 'Ad tracking cookies',
        );

        self::assertFalse($category->required);
        self::assertFalse($category->defaultEnabled);
    }

    #[Test]
    public function requiredCategoryCanBeConstructed(): void
    {
        $category = new ConsentCategory(
            key: 'necessary',
            label: 'Necessary',
            description: 'Essential site cookies',
            required: true,
            defaultEnabled: true,
        );

        self::assertTrue($category->required);
        self::assertTrue($category->defaultEnabled);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $category = ConsentCategory::fromArray('analytics', [
            'label' => 'Analytics Cookies',
            'description' => 'Help us understand site usage',
            'required' => false,
            'default_enabled' => true,
        ]);

        self::assertSame('analytics', $category->key);
        self::assertSame('Analytics Cookies', $category->label);
        self::assertSame('Help us understand site usage', $category->description);
        self::assertFalse($category->required);
        self::assertTrue($category->defaultEnabled);
    }

    #[Test]
    public function fromArrayUsesKeyAsLabelWhenMissing(): void
    {
        $category = ConsentCategory::fromArray('preferences', []);

        self::assertSame('preferences', $category->key);
        self::assertSame('preferences', $category->label);
        self::assertSame('', $category->description);
        self::assertFalse($category->required);
        self::assertFalse($category->defaultEnabled);
    }

    #[Test]
    public function fromArrayIgnoresNonStringLabel(): void
    {
        $category = ConsentCategory::fromArray('test', [
            'label' => 123,
            'description' => false,
        ]);

        self::assertSame('test', $category->label);
        self::assertSame('', $category->description);
    }

    #[Test]
    public function fromArrayIgnoresNonBoolRequired(): void
    {
        $category = ConsentCategory::fromArray('test', [
            'required' => 'yes',
            'default_enabled' => 1,
        ]);

        self::assertFalse($category->required);
        self::assertFalse($category->defaultEnabled);
    }
}
