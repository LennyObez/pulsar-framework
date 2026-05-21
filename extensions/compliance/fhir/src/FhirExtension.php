<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Fhir\Rest\FhirController;
use Pulsar\Routing\RouterInterface;

/**
 * HL7 FHIR R4/R5 extension providing healthcare data interoperability.
 *
 * Provides FHIR resource types, a RESTful API, terminology services,
 * SMART on FHIR scope enforcement, and audit event export capabilities.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FhirExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/fhir';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings handled by FhirServiceProvider
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->registerFhirRoutes($router);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            FhirServiceProvider::class,
        ];
    }

    private function registerFhirRoutes(RouterInterface $router): void
    {
        $basePath = '/fhir';

        // CapabilityStatement (metadata)
        $router->get("$basePath/metadata", [FhirController::class, 'metadata'], 'fhir.metadata');

        // SMART well-known configuration
        $router->get('/.well-known/smart-configuration', 'fhir.smart.configuration');

        // Resource interactions
        $router->get("$basePath/{type}", [FhirController::class, 'search'], 'fhir.search');
        $router->get("$basePath/{type}/{id}", [FhirController::class, 'read'], 'fhir.read');
        $router->post("$basePath/{type}", [FhirController::class, 'create'], 'fhir.create');
        $router->put("$basePath/{type}/{id}", [FhirController::class, 'update'], 'fhir.update');
        $router->delete("$basePath/{type}/{id}", [FhirController::class, 'delete'], 'fhir.delete');

        // Batch/Transaction
        $router->post($basePath, [FhirController::class, 'batch'], 'fhir.batch');
    }
}
