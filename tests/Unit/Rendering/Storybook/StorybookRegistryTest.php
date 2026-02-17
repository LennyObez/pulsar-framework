<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering\Storybook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\Storybook\ComponentStory;
use Pulsar\Rendering\Storybook\StorybookRegistry;

#[CoversClass(StorybookRegistry::class)]
#[CoversClass(ComponentStory::class)]
final class StorybookRegistryTest extends TestCase
{
    #[Test]
    public function starts_empty(): void
    {
        $registry = new StorybookRegistry();

        self::assertSame(0, $registry->count());
        self::assertSame([], $registry->all());
        self::assertSame([], $registry->categories());
        self::assertSame([], $registry->flat());
    }

    #[Test]
    public function add_and_retrieve_story(): void
    {
        $story = new ComponentStory(
            name: 'Primary Button',
            description: 'A styled button',
            componentClass: 'App\\Ui\\Button',
            props: ['label' => 'Click me'],
            category: 'Buttons',
        );

        $registry = new StorybookRegistry();
        $registry->add($story);

        self::assertSame(1, $registry->count());
        self::assertSame(['Buttons'], $registry->categories());
    }

    #[Test]
    public function groups_stories_by_category(): void
    {
        $registry = new StorybookRegistry();

        $registry->add(new ComponentStory('Btn 1', 'desc', 'A', [], 'Buttons'));
        $registry->add(new ComponentStory('Btn 2', 'desc', 'B', [], 'Buttons'));
        $registry->add(new ComponentStory('Card 1', 'desc', 'C', [], 'Cards'));

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('Buttons', $all);
        self::assertArrayHasKey('Cards', $all);
        self::assertCount(2, $all['Buttons']);
        self::assertCount(1, $all['Cards']);
    }

    #[Test]
    public function for_category_returns_stories(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('Btn', 'desc', 'A', [], 'Buttons'));
        $registry->add(new ComponentStory('Card', 'desc', 'B', [], 'Cards'));

        $buttons = $registry->forCategory('Buttons');

        self::assertCount(1, $buttons);
        self::assertSame('Btn', $buttons[0]->name);
    }

    #[Test]
    public function for_category_returns_empty_for_unknown(): void
    {
        $registry = new StorybookRegistry();

        self::assertSame([], $registry->forCategory('Unknown'));
    }

    #[Test]
    public function flat_returns_all_stories_in_order(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('A', 'desc', 'X', [], 'Cat1'));
        $registry->add(new ComponentStory('B', 'desc', 'Y', [], 'Cat2'));
        $registry->add(new ComponentStory('C', 'desc', 'Z', [], 'Cat1'));

        $flat = $registry->flat();

        self::assertCount(3, $flat);
        self::assertSame('A', $flat[0]->name);
        self::assertSame('C', $flat[1]->name);
        self::assertSame('B', $flat[2]->name);
    }

    #[Test]
    public function find_by_name(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('Alpha', 'desc', 'A', [], 'Cat'));
        $registry->add(new ComponentStory('Beta', 'desc', 'B', [], 'Cat'));

        $found = $registry->find('Beta');

        self::assertNotNull($found);
        self::assertSame('Beta', $found->name);
        self::assertSame('B', $found->componentClass);
    }

    #[Test]
    public function find_returns_null_for_unknown(): void
    {
        $registry = new StorybookRegistry();

        self::assertNull($registry->find('NotExist'));
    }

    #[Test]
    public function count_tracks_all_stories(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('A', 'desc', 'X', [], 'Cat1'));
        $registry->add(new ComponentStory('B', 'desc', 'Y', [], 'Cat2'));
        $registry->add(new ComponentStory('C', 'desc', 'Z', [], 'Cat1'));

        self::assertSame(3, $registry->count());
    }

    #[Test]
    public function component_story_uses_default_category(): void
    {
        $story = new ComponentStory(
            name: 'Test',
            description: 'desc',
            componentClass: 'App\\Component',
            props: ['key' => 'val'],
        );

        self::assertSame('General', $story->category);
        self::assertSame(['key' => 'val'], $story->props);
    }

    #[Test]
    public function find_searches_across_categories(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('A', 'desc', 'X', [], 'Cat1'));
        $registry->add(new ComponentStory('B', 'desc', 'Y', [], 'Cat2'));
        $registry->add(new ComponentStory('Target', 'desc', 'Z', [], 'Cat3'));

        $found = $registry->find('Target');

        self::assertNotNull($found);
        self::assertSame('Cat3', $found->category);
    }
}
