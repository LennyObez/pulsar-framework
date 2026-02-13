<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Escaper\ContextEscaper;
use Pulsar\View\Command\PlaygroundServeCommand;
use Pulsar\View\Command\ViewCompileCommand;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\Engine\TemplateEngineInterface;
use Pulsar\View\Engine\TemplateInheritance;
use Pulsar\View\Escaping\AttributeEscaper;
use Pulsar\View\Escaping\CssEscaper;
use Pulsar\View\Escaping\EscaperInterface;
use Pulsar\View\Escaping\HtmlEscaper;
use Pulsar\View\Escaping\JsEscaper;
use Pulsar\View\Escaping\UrlEscaper;
use Pulsar\View\Sandbox\SandboxConfig;
use Pulsar\View\Sandbox\SandboxEngine;
use Pulsar\View\ViewConfig;

use function dirname;

/**
 * Wires the View template engine into the container.
 *
 * Only activates when ViewConfig is present in the config repository.
 */
#[Internal]
final readonly class ViewWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(ViewConfig::class)) {
            return;
        }

        /** @var ViewConfig $config */
        $config = $repository->get(ViewConfig::class);
        $container->instance(ViewConfig::class, $config);

        // Template cache
        $cache = new TemplateCache($config->cachePath);
        $container->instance(TemplateCache::class, $cache);

        // Template compiler
        $compiler = new TemplateCompiler($config, $cache);
        $container->instance(TemplateCompiler::class, $compiler);

        // Directive registry (registers all built-in directives and binds to compiler)
        $directiveRegistry = new DirectiveRegistry($config);
        $directiveRegistry->registerBuiltins();
        $directiveRegistry->bindTo($compiler);
        $container->instance(DirectiveRegistry::class, $directiveRegistry);

        // Template inheritance / runtime
        $inheritance = new TemplateInheritance();
        $container->instance(TemplateInheritance::class, $inheritance);

        // Template engine (public API)
        $engine = new TemplateEngine($compiler);
        $container->instance(TemplateEngineInterface::class, $engine);
        $container->instance(TemplateEngine::class, $engine);

        // Configure Response::view() static engine so controllers can use it directly
        \Pulsar\Http\Message\Response::setTemplateEngine($engine);

        // Escapers: register both the View-layer escapers and the security ContextEscaper
        $container->instance(EscaperInterface::class, new HtmlEscaper());
        $container->instance(HtmlEscaper::class, new HtmlEscaper());
        $container->instance(UrlEscaper::class, new UrlEscaper());
        $container->instance(AttributeEscaper::class, new AttributeEscaper());
        $container->instance(JsEscaper::class, new JsEscaper());
        $container->instance(CssEscaper::class, new CssEscaper());
        $container->instance(ContextEscaper::class, new ContextEscaper());

        // Sandbox engine for untrusted templates
        $sandboxConfig = SandboxConfig::fromViewConfig($config);
        $sandboxEngine = new SandboxEngine($sandboxConfig);
        $container->instance(SandboxConfig::class, $sandboxConfig);
        $container->instance(SandboxEngine::class, $sandboxEngine);

        // CLI commands
        $container->instance(ViewCompileCommand::class, new ViewCompileCommand($compiler, $config));
        $projectRoot = $configManager->configPath() !== null
            ? dirname($configManager->configPath())
            : '.';
        $container->instance(PlaygroundServeCommand::class, new PlaygroundServeCommand($projectRoot));
    }
}
