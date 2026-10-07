<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\Variation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\Variation\BlockVariation;
use Pulsar\Extension\Cms\BlockEditor\Variation\CoreVariations;
use Pulsar\Extension\Cms\BlockEditor\Variation\VariationRegistry;

#[CoversClass(VariationRegistry::class)]
#[CoversClass(BlockVariation::class)]
#[CoversClass(CoreVariations::class)]
final class VariationRegistryTest extends TestCase
{
    private VariationRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new VariationRegistry();
    }

    public function testRegisterAndForType(): void
    {
        $variation = new BlockVariation('youtube', 'embed', 'YouTube', 'Embed YouTube');

        $this->registry->register($variation);

        $variations = $this->registry->forType('embed');
        self::assertCount(1, $variations);
        self::assertSame('youtube', $variations[0]->name);
    }

    public function testForTypeReturnsEmptyForUnknown(): void
    {
        self::assertSame([], $this->registry->forType('nonexistent'));
    }

    public function testFindSpecificVariation(): void
    {
        $this->registry->register(new BlockVariation('youtube', 'embed', 'YouTube', 'YouTube embed'));
        $this->registry->register(new BlockVariation('vimeo', 'embed', 'Vimeo', 'Vimeo embed'));

        $found = $this->registry->find('embed', 'vimeo');

        self::assertNotNull($found);
        self::assertSame('vimeo', $found->name);
        self::assertSame('Vimeo', $found->title);
    }

    public function testFindReturnsNullForUnknown(): void
    {
        self::assertNull($this->registry->find('embed', 'nonexistent'));
    }

    public function testAllReturnsAllVariations(): void
    {
        $this->registry->register(new BlockVariation('youtube', 'embed', 'YouTube', ''));
        $this->registry->register(new BlockVariation('bold', 'paragraph', 'Bold', ''));

        self::assertCount(2, $this->registry->all());
    }

    public function testVariationDefaults(): void
    {
        $variation = new BlockVariation(
            name: 'youtube',
            blockType: 'embed',
            title: 'YouTube',
            description: 'YouTube embed',
            defaults: ['type' => 'youtube'],
            icon: 'youtube',
        );

        self::assertSame(['type' => 'youtube'], $variation->defaults);
        self::assertSame('youtube', $variation->icon);
    }

    public function testCoreVariationsRegistration(): void
    {
        CoreVariations::register($this->registry);

        $embedVariations = $this->registry->forType('embed');
        self::assertCount(5, $embedVariations);

        $names = array_map(fn(BlockVariation $v) => $v->name, $embedVariations);
        self::assertContains('youtube', $names);
        self::assertContains('vimeo', $names);
        self::assertContains('twitter', $names);
        self::assertContains('codepen', $names);
        self::assertContains('spotify', $names);
    }
}
