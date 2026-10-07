<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionBootstrap;

/**
 * Registers extension and project-theme view directories with the template
 * engine after extensions boot.
 *
 * Adds each booted extension's resources/views/ directory (enabling
 * namespace-prefixed templates such as "cms::public.pages.page") and each
 * configured template path's theme/ subdirectory, then rebuilds the
 * ViewConfig, TemplateCompiler, and TemplateEngine with the combined paths.
 * Extracted from {@see \Pulsar\Core\Kernel}; boot-time only.
 */
#[Internal]
final class ExtensionViewPathRegistrar
{
    public static function register(ContainerInterface $container, ?ExtensionBootstrap $extensionBootstrap): void
    {
        if (!$container->has(\Pulsar\View\Engine\TemplateCompiler::class)) {
            return;
        }

        // Get extension paths from the bootstrap's manifests. An empty list is
        // not an early exit: a project with no extensions installed may still
        // organize templates under resources/views/theme/, and those theme
        // paths (computed below) must still be registered. The combined guard
        // further down returns only when BOTH extension and theme paths are
        // empty.
        $manifests = $extensionBootstrap?->getManifests() ?? [];

        $extensionViewPaths = [];

        foreach ($manifests as $manifest) {
            if ($manifest->path !== '') {
                $viewsDir = $manifest->path . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';

                if (is_dir($viewsDir)) {
                    $extensionViewPaths[] = $viewsDir;
                }
            }
        }

        /** @var \Pulsar\View\ViewConfig $existingConfig */
        $existingConfig = $container->get(\Pulsar\View\ViewConfig::class);

        // Also add the project's theme/ subdirectory as a search path.
        // Projects may organize templates under resources/views/theme/ for separation
        // from framework-provided templates. This is searched after the root views dir.
        $themeViewPaths = [];

        foreach ($existingConfig->templatePaths as $viewPath) {
            $themePath = $viewPath . DIRECTORY_SEPARATOR . 'theme';

            if (is_dir($themePath)) {
                $themeViewPaths[] = $themePath;
            }
        }

        if ($extensionViewPaths === [] && $themeViewPaths === []) {
            return;
        }

        // Rebuild the ViewConfig and TemplateCompiler with all paths

        $updatedConfig = new \Pulsar\View\ViewConfig(
            templatePaths: [...$existingConfig->templatePaths, ...$themeViewPaths, ...$extensionViewPaths],
            cachePath: $existingConfig->cachePath,
            autoEscape: $existingConfig->autoEscape,
            activeTheme: $existingConfig->activeTheme,
            phpDirectiveAllowed: $existingConfig->phpDirectiveAllowed,
            sandboxMode: $existingConfig->sandboxMode,
            sandboxStepLimit: $existingConfig->sandboxStepLimit,
            sandboxLoopLimit: $existingConfig->sandboxLoopLimit,
            sandboxOutputSizeLimit: $existingConfig->sandboxOutputSizeLimit,
            sandboxWallClockCheckInterval: $existingConfig->sandboxWallClockCheckInterval,
        );

        // Replace the config and rebuild the compiler with the new paths
        $container->instance(\Pulsar\View\ViewConfig::class, $updatedConfig);

        /** @var \Pulsar\View\Engine\TemplateCache $cache */
        $cache = $container->get(\Pulsar\View\Engine\TemplateCache::class);
        $newCompiler = new \Pulsar\View\Engine\TemplateCompiler($updatedConfig, $cache);

        // Re-register directives on the new compiler
        if ($container->has(\Pulsar\View\Directive\DirectiveRegistry::class)) {
            /** @var \Pulsar\View\Directive\DirectiveRegistry $directives */
            $directives = $container->get(\Pulsar\View\Directive\DirectiveRegistry::class);
            $directives->bindTo($newCompiler);
        }

        $container->instance(\Pulsar\View\Engine\TemplateCompiler::class, $newCompiler);

        // Rebuild the engine with the new compiler, carrying the shared-data /
        // view-composer store across the swap: shares and composers registered
        // on the original engine at boot live in that store, and omitting it
        // here would silently discard them (the constructor would fall back to
        // an empty store) for every project with a theme/ or extension views.
        $composers = null;
        if ($container->has(\Pulsar\View\Engine\ViewComposers::class)) {
            /** @var \Pulsar\View\Engine\ViewComposers $composers */
            $composers = $container->get(\Pulsar\View\Engine\ViewComposers::class);
        }

        $newEngine = new \Pulsar\View\Engine\TemplateEngine($newCompiler, $composers);
        $container->instance(\Pulsar\View\Engine\TemplateEngineInterface::class, $newEngine);
        $container->instance(\Pulsar\View\Engine\TemplateEngine::class, $newEngine);
        \Pulsar\Http\Message\Response::setTemplateEngine($newEngine);
    }
}
