<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering\Storybook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\Storybook\ComponentStory;
use Pulsar\Rendering\Storybook\StorybookRegistry;
use Pulsar\Rendering\Storybook\StorybookRenderer;

/**
 * Edge case tests for StorybookRenderer.
 */
#[CoversClass(StorybookRenderer::class)]
#[CoversClass(StorybookRegistry::class)]
#[CoversClass(ComponentStory::class)]
final class StorybookRendererEdgeTest extends TestCase
{
    #[Test]
    public function renderIndexWithEmptyRegistryProducesValidHtml(): void
    {
        $registry = new StorybookRegistry();
        $renderer = new StorybookRenderer($registry);

        $html = $renderer->renderIndex();

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('Pulsar Component Storybook', $html);
        self::assertStringContainsString('sb-sidebar', $html);
        self::assertStringContainsString('sb-content', $html);
    }

    #[Test]
    public function renderIndexRendersStoriesGroupedByCategory(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('Button', 'A button', 'App\\Button', [], 'Forms'));
        $registry->add(new ComponentStory('Alert', 'An alert', 'App\\Alert', [], 'Feedback'));

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderIndex();

        self::assertStringContainsString('Forms', $html);
        self::assertStringContainsString('Feedback', $html);
        self::assertStringContainsString('Button', $html);
        self::assertStringContainsString('Alert', $html);
    }

    #[Test]
    public function renderStoryRendersIsolatedView(): void
    {
        $registry = new StorybookRegistry();
        $story = new ComponentStory('Solo', 'Solo test', 'NonExistentClass', []);

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderStory($story);

        self::assertStringContainsString('sb-isolated', $html);
        self::assertStringContainsString('Solo', $html);
        self::assertStringContainsString('Component class not found', $html);
    }

    #[Test]
    public function renderStoryEscapesHtmlInNameAndDescription(): void
    {
        $registry = new StorybookRegistry();
        $story = new ComponentStory(
            '<script>alert(1)</script>',
            '<img onerror=alert(1)>',
            'SomeClass',
            [],
        );

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderStory($story);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img onerror', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function registryFlatReturnsAllStoriesAsList(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('A', 'a', 'ClassA', [], 'Cat1'));
        $registry->add(new ComponentStory('B', 'b', 'ClassB', [], 'Cat2'));
        $registry->add(new ComponentStory('C', 'c', 'ClassC', [], 'Cat1'));

        self::assertCount(3, $registry->flat());
    }

    #[Test]
    public function registryFindReturnsStoryByName(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('Target', 'desc', 'SomeClass', []));

        $found = $registry->find('Target');
        self::assertNotNull($found);
        self::assertSame('Target', $found->name);

        self::assertNull($registry->find('Missing'));
    }

    #[Test]
    public function registryCountReturnsTotalStories(): void
    {
        $registry = new StorybookRegistry();

        self::assertSame(0, $registry->count());

        $registry->add(new ComponentStory('A', '', '', [], 'Cat1'));
        $registry->add(new ComponentStory('B', '', '', [], 'Cat2'));

        self::assertSame(2, $registry->count());
    }

    #[Test]
    public function registryCategoriesReturnsUniqueCategories(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('A', '', '', [], 'Forms'));
        $registry->add(new ComponentStory('B', '', '', [], 'Layout'));
        $registry->add(new ComponentStory('C', '', '', [], 'Forms'));

        $categories = $registry->categories();

        self::assertContains('Forms', $categories);
        self::assertContains('Layout', $categories);
        self::assertCount(2, $categories);
    }

    #[Test]
    public function registryForCategoryReturnsOnlyMatchingStories(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('A', '', '', [], 'Forms'));
        $registry->add(new ComponentStory('B', '', '', [], 'Layout'));

        $formStories = $registry->forCategory('Forms');

        self::assertCount(1, $formStories);
        self::assertSame('A', $formStories[0]->name);

        self::assertSame([], $registry->forCategory('Missing'));
    }

    #[Test]
    public function componentStoryHasDefaultCategory(): void
    {
        $story = new ComponentStory('Test', 'desc', 'Class', ['k' => 'v']);

        self::assertSame('General', $story->category);
        self::assertSame('Test', $story->name);
        self::assertSame('desc', $story->description);
        self::assertSame('Class', $story->componentClass);
        self::assertSame(['k' => 'v'], $story->props);
    }
}
