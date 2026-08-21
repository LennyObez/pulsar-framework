<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Api\OpenApi\Command\ApiRoutesCommand;
use Pulsar\Api\OpenApi\Command\ApiSpecCommand;
use Pulsar\Config\CallableConfigLoader;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Core\Wiring\ProvidesConfigLoaders;
use Pulsar\Core\Wiring\ServiceWiringInterface;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\RouteAccessRegistrar;
use Pulsar\Routing\Router;

use function rtrim;

/**
 * Wires the OpenAPI spec generation and Swagger UI into the container.
 *
 * Only activates Swagger UI routes when the config has `swagger_ui_enabled = true`.
 * The CLI commands are always registered.
 *
 * Owns config/openapi.php: its loader builds {@see OpenApiConfig} into the
 * ConfigRepository during config load (the single source of truth), so wire()
 * resolves it from the repository instead of always defaulting.
 */
#[Internal(reason: 'Composition root wiring')]
final readonly class OpenApiWiring implements ServiceWiringInterface, ProvidesConfigLoaders
{
    public function configLoaders(): array
    {
        return [
            'openapi' => new CallableConfigLoader(
                OpenApiConfig::class,
                static fn(array $data): object => OpenApiConfig::fromArray($data),
            ),
        ];
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        $config = $repository->has(OpenApiConfig::class)
            ? $repository->get(OpenApiConfig::class)
            : new OpenApiConfig();

        /** @var OpenApiConfig $config */
        $container->instance(OpenApiConfig::class, $config);

        // Schema inferrer
        $schemaInferrer = new SchemaInferrer();
        $container->instance(SchemaInferrer::class, $schemaInferrer);

        // Spec generator
        $specGenerator = new SpecGenerator($config, $schemaInferrer);
        $container->instance(SpecGenerator::class, $specGenerator);

        // Endpoint scanner
        $scanner = new EndpointScanner();
        $container->instance(EndpointScanner::class, $scanner);

        // CLI commands (requires KernelInterface from container)
        /** @var KernelInterface $kernel */
        $kernel = $container->get(KernelInterface::class);

        $container->instance(
            ApiSpecCommand::class,
            new ApiSpecCommand($kernel, $config),
        );
        $container->instance(
            ApiRoutesCommand::class,
            new ApiRoutesCommand($kernel),
        );

        // Swagger UI routes (only if enabled)
        if ($config->swaggerUiEnabled) {
            $specPath = resolve_path($config->outputPath);
            $specRoute = rtrim($config->swaggerUiRoute, '/') . '/openapi.json';

            $controller = new SwaggerUiController($specPath, $specRoute);
            $container->instance(SwaggerUiController::class, $controller);

            $logger = $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null;
            /** @var LoggerInterface|null $logger */

            // Operator-facing, so it needs a permission rather than a token: the
            // reader is a person in a browser with a session, and Swagger UI cannot
            // attach a Bearer header to its own page load.
            //
            // Anonymous was the wrong default for what this publishes. The spec
            // enumerates every endpoint, parameter, and schema the deployment
            // serves -- in a banking or healthcare install that is a map of the
            // regulated surface, handed to whoever asks. Turning swagger_ui_enabled
            // on is an explicit act; publishing the estate to the internet should
            // be a second one, and it is: grant `api.docs.read`.
            //
            // If authentication is disabled entirely (security.auth is null) the
            // `auth` alias does not exist, nothing could enforce the permission,
            // and RouteAccessRegistrar registers neither route -- 404 rather than a
            // guard that cannot run.
            $docsReason = 'Publishes the full API contract (every endpoint, parameter and schema) '
                . 'of this deployment; readable by an authenticated principal holding '
                . 'api.docs.read, which nobody holds until it is granted.';

            $routes = new RouteAccessRegistrar($router, $middlewareRegistry, $logger);
            $routes->authenticated([Method::GET], $config->swaggerUiRoute, [$controller, 'ui'], 'api.docs.ui', ['api.docs.read'], $docsReason);
            $routes->authenticated([Method::GET], $specRoute, [$controller, 'spec'], 'api.docs.spec', ['api.docs.read'], $docsReason);
        }
    }
}
