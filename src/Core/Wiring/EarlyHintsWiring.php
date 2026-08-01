<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Http3\EarlyHints;
use Pulsar\Http\Http3\EarlyHintsInterface;
use Pulsar\Http\Middleware\EarlyHintsMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

/**
 * Wires 103 Early Hints onto the request pipeline.
 *
 * Which resources are worth hinting is a property of an application's shell, not of
 * the framework: hinting the wrong asset costs bandwidth on every navigation and
 * hinting none costs nothing. So the framework provides the mechanism and binds it
 * the moment an application registers a prepared {@see EarlyHints} set in the
 * container — no configuration key that does nothing until someone fills it in, and
 * no middleware in the pipeline for applications that never asked for one.
 *
 * `prepend()`, not `pipe()`: a hint is only worth sending ahead of the work it
 * overlaps with, so it must go out before any other middleware spends time. An inner
 * position would still be correct, just progressively less useful the deeper it sits.
 */
#[Internal]
final readonly class EarlyHintsWiring implements ServiceWiringInterface
{
    #[Override]
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        // Applications bind either the interface (custom emitter) or the framework's
        // own builder; both are legitimate registrations, so both are honoured.
        foreach ([EarlyHintsInterface::class, EarlyHints::class] as $id) {
            if (!$container->has($id)) {
                continue;
            }

            $hints = $container->get($id);

            if ($hints instanceof EarlyHintsInterface) {
                $middleware->prepend(new EarlyHintsMiddleware($hints));

                return;
            }
        }
    }
}
