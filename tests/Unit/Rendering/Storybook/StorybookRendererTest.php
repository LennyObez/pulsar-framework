<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering\Storybook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\Storybook\ComponentStory;
use Pulsar\Rendering\Storybook\StorybookRegistry;
use Pulsar\Rendering\Storybook\StorybookRenderer;

#[CoversClass(StorybookRenderer::class)]
final class StorybookRendererTest extends TestCase
{
    #[Test]
    public function renderIndexProducesHtmlDocument(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory(
            'TestStory',
            'A test story',
            'NonExistent\\Component',
            [],
            'TestCat',
        ));

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderIndex();

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('Pulsar Component Storybook', $html);
        self::assertStringContainsString('TestStory', $html);
        self::assertStringContainsString('TestCat', $html);
        self::assertStringContainsString('sb-sidebar', $html);
        self::assertStringContainsString('sb-content', $html);
    }

    #[Test]
    public function renderIndexWithMultipleCategoriesShowsAll(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory('S1', 'D1', 'A\\B', [], 'Buttons'));
        $registry->add(new ComponentStory('S2', 'D2', 'A\\C', [], 'Cards'));

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderIndex();

        self::assertStringContainsString('Buttons', $html);
        self::assertStringContainsString('Cards', $html);
        self::assertStringContainsString('S1', $html);
        self::assertStringContainsString('S2', $html);
    }

    #[Test]
    public function renderStoryInIsolation(): void
    {
        $registry = new StorybookRegistry();
        $story = new ComponentStory('Solo', 'Isolated', 'Fake\\Class', []);

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderStory($story);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('sb-isolated', $html);
        self::assertStringContainsString('Solo', $html);
        self::assertStringContainsString('Component class not found', $html);
    }

    #[Test]
    public function renderStoryWithNonExistentClassShowsError(): void
    {
        $registry = new StorybookRegistry();
        $story = new ComponentStory('Bad', 'desc', 'Missing\\Class', []);

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderStory($story);

        self::assertStringContainsString('Component class not found', $html);
    }

    #[Test]
    public function renderEscapesHtmlEntities(): void
    {
        $registry = new StorybookRegistry();
        $registry->add(new ComponentStory(
            'Story<script>',
            'Desc&"quotes"',
            'A\\B',
            [],
            'Cat<b>',
        ));

        $renderer = new StorybookRenderer($registry);
        $html = $renderer->renderIndex();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
