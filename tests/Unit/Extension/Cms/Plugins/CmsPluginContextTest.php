<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;
use Pulsar\Extension\Cms\Plugins\CmsPluginContext;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Extension\Cms\Plugins\ScopedContainerProxy;

#[CoversClass(CmsPluginContext::class)]
final class CmsPluginContextTest extends TestCase
{
    private CmsPluginContext $context;
    private HookRegistry $hookRegistry;

    protected function setUp(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $proxy = new ScopedContainerProxy($container, 'test-plugin');
        $this->hookRegistry = new HookRegistry();
        $this->context = new CmsPluginContext('test-plugin', $proxy, $this->hookRegistry);
    }

    #[Test]
    public function pluginSlugReturnsSlug(): void
    {
        self::assertSame('test-plugin', $this->context->pluginSlug());
    }

    #[Test]
    public function containerReturnsScopedProxy(): void
    {
        self::assertInstanceOf(ScopedContainerProxy::class, $this->context->container());
    }

    #[Test]
    public function registerContentTypeAddsDefinition(): void
    {
        $definition = new ContentTypeDefinition(
            type: 'recipe',
            label: 'Recipe',
            icon: 'utensils',
            fields: [],
        );

        $this->context->registerContentType($definition);
        $types = $this->context->contentTypes;

        self::assertCount(1, $types);
        self::assertSame('recipe', $types[0]->type);
    }

    #[Test]
    public function registerAdminPageStoresPageData(): void
    {
        $handler = static fn(): string => 'page-html';

        $this->context->registerAdminPage('/custom-page', 'Custom Page', $handler);
        $pages = $this->context->adminPages;

        self::assertCount(1, $pages);
        self::assertSame('/custom-page', $pages[0]['route']);
        self::assertSame('Custom Page', $pages[0]['label']);
    }

    #[Test]
    public function registerHookDelegatesToHookRegistry(): void
    {
        $callback = static fn(): string => 'modified';

        $this->context->registerHook('content.before_save', $callback, priority: 5);

        self::assertTrue($this->hookRegistry->has('content.before_save'));
        $callbacks = $this->hookRegistry->getCallbacks('content.before_save');
        self::assertCount(1, $callbacks);
        self::assertSame(5, $callbacks[0]['priority']);
        self::assertSame('test-plugin', $callbacks[0]['pluginSlug']);
    }

    #[Test]
    public function registerShortcodeStoresShortcodeData(): void
    {
        $handler = static fn(string $content): string => "<div>{$content}</div>";

        $this->context->registerShortcode('alert', $handler);
        $shortcodes = $this->context->shortcodes;

        self::assertCount(1, $shortcodes);
        self::assertSame('alert', $shortcodes[0]['name']);
    }

    #[Test]
    public function registerBlockTypeStoresBlockData(): void
    {
        $renderer = static fn(array $data): string => '<div>Block</div>';

        $this->context->registerBlockType('custom-card', $renderer);
        $blockTypes = $this->context->blockTypes;

        self::assertCount(1, $blockTypes);
        self::assertSame('custom-card', $blockTypes[0]['name']);
    }

    #[Test]
    public function multipleRegistrationsAccumulate(): void
    {
        $this->context->registerShortcode('one', static fn(): string => '');
        $this->context->registerShortcode('two', static fn(): string => '');
        $this->context->registerBlockType('block-a', static fn(): string => '');

        self::assertCount(2, $this->context->shortcodes);
        self::assertCount(1, $this->context->blockTypes);
    }

    #[Test]
    public function gettersReturnEmptyByDefault(): void
    {
        self::assertSame([], $this->context->contentTypes);
        self::assertSame([], $this->context->adminPages);
        self::assertSame([], $this->context->shortcodes);
        self::assertSame([], $this->context->blockTypes);
    }
}
