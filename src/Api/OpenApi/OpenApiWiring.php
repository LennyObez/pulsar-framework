<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use Pulsar\Api\Internal;
use Pulsar\Api\OpenApi\Command\ApiRoutesCommand;
use Pulsar\Api\OpenApi\Command\ApiSpecCommand;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Core\Wiring\ServiceWiringInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function rtrim;

/**
 * Wires the OpenAPI spec generation and Swagger UI into the container.
 *
 * Only activates Swagger UI routes when the config has `swagger_ui_enabled = true`.
 * The CLI commands are always registered.
 */
#[Internal(reason: 'Composition root wiring')]
final readonly class OpenApiWiring implements ServiceWiringInterface
{
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

            $router->get($config->swaggerUiRoute, [$controller, 'ui'], 'api.docs.ui');
            $router->get($specRoute, [$controller, 'spec'], 'api.docs.spec');
        }
    }
}
