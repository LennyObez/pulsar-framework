<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering\Storybook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\Storybook\ComponentStory;

#[CoversClass(ComponentStory::class)]
final class ComponentStoryTest extends TestCase
{
    #[Test]
    public function constructorStoresAllFields(): void
    {
        $story = new ComponentStory(
            name: 'Primary Button',
            description: 'A button in primary state',
            componentClass: 'App\\Ui\\Button',
            props: ['label' => 'Click me', 'variant' => 'primary'],
            category: 'Buttons',
        );

        self::assertSame('Primary Button', $story->name);
        self::assertSame('A button in primary state', $story->description);
        self::assertSame('App\\Ui\\Button', $story->componentClass);
        self::assertSame(['label' => 'Click me', 'variant' => 'primary'], $story->props);
        self::assertSame('Buttons', $story->category);
    }

    #[Test]
    public function defaultCategoryIsGeneral(): void
    {
        $story = new ComponentStory('Test', 'desc', 'App\\X', []);

        self::assertSame('General', $story->category);
    }

    #[Test]
    public function emptyPropsIsValid(): void
    {
        $story = new ComponentStory('Empty', 'no props', 'App\\Y', []);

        self::assertSame([], $story->props);
    }
}
