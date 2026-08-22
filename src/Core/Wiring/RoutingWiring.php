<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\RoutingConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\CanonicalPathMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

/**
 * Wires request-URL canonicalization.
 *
 * The canonicalization middleware MUST run OUTERMOST — before locale-prefix
 * stripping. It redirects on `$request->getUri()->getPath()`, and
 * {@see \Pulsar\I18n\Locale\LocalePrefixMiddleware} rewrites that path to drop
 * the locale prefix; if canonicalization ran after that strip, `/nl/coaching/`
 * would be seen as the already-stripped `/coaching/` and permanently redirect
 * to `/coaching` — silently losing the locale prefix on every non-canonical
 * URL, cached by browsers and crawlers as a 301. This ordering is therefore a
 * correctness constraint, not a description.
 *
 * It is enforced structurally: the middleware is {@see MiddlewarePipeline::prepend()}ed,
 * so it lands at the front of the pipeline regardless of where this wiring sits
 * in {@see WiringList} relative to the one that pipes LocalePrefixMiddleware.
 * Reordering the boot list cannot break the invariant; only removing the
 * prepend can. (`pipe()` would leave the guarantee resting on WiringList line
 * order — RoutingWiring happening to precede I18nWiring — which nothing checks.)
 *
 * When no `config/routing.php` is present the repository has no RoutingConfig,
 * so the default (canonicalization OFF) applies and nothing is piped — the
 * router keeps its historical forgiving behaviour, preserving backwards
 * compatibility.
 */
#[Internal]
final readonly class RoutingWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        $config = $repository->has(RoutingConfig::class)
            ? $repository->get(RoutingConfig::class)
            : new RoutingConfig();

        /** @var RoutingConfig $config */
        $container->instance(RoutingConfig::class, $config);

        if ($config->redirectToCanonicalPath) {
            // prepend(), not pipe(): this must be OUTERMOST — ahead of the
            // locale-prefix strip — no matter the boot order. See the class
            // docblock for why an inner position silently drops locale prefixes.
            $middleware->prepend(new CanonicalPathMiddleware());
        }
    }
}
