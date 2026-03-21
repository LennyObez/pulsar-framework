<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeDefinition;

/**
 * Scoped API surface available to CMS plugins during register() and boot().
 *
 * Provides methods for registering content types, admin pages, hooks,
 * shortcodes, and block types. All registrations are namespaced by plugin slug.
 *
 * @psalm-api Public extension API constructed by CmsPluginManager and passed
 *            to plugin register() / boot() entry points.
 * @api
 */
#[Api(since: '1.0.0')]
final class CmsPluginContext
{
    /** @var list<ContentTypeDefinition> */
    public private(set) array $contentTypes = [];

    /** @var list<array{route: string, label: string, handler: Closure}> */
    public private(set) array $adminPages = [];

    /** @var list<array{name: string, handler: Closure}> */
    public private(set) array $shortcodes = [];

    /** @var list<array{name: string, renderer: Closure}> */
    public private(set) array $blockTypes = [];

    public function __construct(
        private readonly string $pluginSlug,
        private readonly ScopedContainerProxy $container,
        private readonly HookRegistry $hookRegistry,
    ) {}

    /**
     * Register a custom content type.
     */
    public function registerContentType(ContentTypeDefinition $definition): void
    {
        $this->contentTypes[] = $definition;
    }

    /**
     * Register an admin page.
     */
    public function registerAdminPage(string $route, string $label, callable $handler): void
    {
        $this->adminPages[] = [
            'route' => $route,
            'label' => $label,
            'handler' => $handler(...),
        ];
    }

    /**
     * Register a hook callback for a named hook point.
     */
    public function registerHook(string $hookPoint, callable $callback, int $priority = 10): void
    {
        $this->hookRegistry->register($hookPoint, $callback(...), $priority, $this->pluginSlug);
    }

    /**
     * Register a shortcode handler.
     */
    public function registerShortcode(string $name, callable $handler): void
    {
        $this->shortcodes[] = [
            'name' => $name,
            'handler' => $handler(...),
        ];
    }

    /**
     * Register a block type renderer.
     */
    public function registerBlockType(string $name, callable $renderer): void
    {
        $this->blockTypes[] = [
            'name' => $name,
            'renderer' => $renderer(...),
        ];
    }

    /**
     * Access the scoped container proxy.
     */
    public function container(): ScopedContainerProxy
    {
        return $this->container;
    }

    /**
     * Get the plugin slug for this context.
     */
    public function pluginSlug(): string
    {
        return $this->pluginSlug;
    }

}
