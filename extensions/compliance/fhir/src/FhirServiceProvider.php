<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Fhir\Audit\FhirAuditEventMapper;
use Pulsar\Extension\Fhir\Config\FhirConfig;
use Pulsar\Extension\Fhir\Internal\DatabaseFhirRepository;
use Pulsar\Extension\Fhir\Internal\InMemoryFhirRepository;
use Pulsar\Extension\Fhir\Internal\TerminologyService;
use Pulsar\Extension\Fhir\Resource\ResourceType;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\FhirController;
use Pulsar\Extension\Fhir\Rest\FhirRepositoryInterface;
use Pulsar\Extension\Fhir\Rest\SearchParameter;
use Pulsar\Extension\Fhir\Smart\SmartScopeEnforcer;
use Pulsar\Extension\Fhir\Terminology\TerminologyServiceInterface;
use Pulsar\Extension\Fhir\Terminology\ValueSetValidator;

/**
 * Wires FHIR services: repository, controller, terminology, SMART scopes, audit mapper.
 */
#[Internal(reason: 'FHIR service wiring; use interfaces for public API')]
final class FhirServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Config
        $config = new FhirConfig();
        $container->instance(FhirConfig::class, $config);

        // Repository: database-backed persistence when a connection is wired
        // (production), in-memory otherwise (tests / minimal bootstraps).
        if ($container->has(ConnectionInterface::class)) {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            $repository = new DatabaseFhirRepository($connection);
        } else {
            $repository = new InMemoryFhirRepository();
        }

        $container->instance(FhirRepositoryInterface::class, $repository);

        // CapabilityStatement
        $capabilityStatement = new CapabilityStatementBuilder($config->serverName, $config->fhirVersion);
        $this->registerResourceCapabilities($capabilityStatement);
        $container->instance(CapabilityStatementBuilder::class, $capabilityStatement);

        // SMART scope enforcer — gates every PHI interaction in the controller.
        $scopeEnforcer = new SmartScopeEnforcer();
        $container->instance(SmartScopeEnforcer::class, $scopeEnforcer);

        // Controller
        $controller = new FhirController($repository, $capabilityStatement, $scopeEnforcer);
        $container->instance(FhirController::class, $controller);

        // Terminology
        $valueSetValidator = new ValueSetValidator();
        $container->instance(ValueSetValidator::class, $valueSetValidator);

        $terminologyService = new TerminologyService($valueSetValidator);
        $container->instance(TerminologyServiceInterface::class, $terminologyService);

        // Audit event mapper
        $container->instance(FhirAuditEventMapper::class, new FhirAuditEventMapper());
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            FhirConfig::class,
            FhirRepositoryInterface::class,
            CapabilityStatementBuilder::class,
            FhirController::class,
            ValueSetValidator::class,
            TerminologyServiceInterface::class,
            SmartScopeEnforcer::class,
            FhirAuditEventMapper::class,
        ];
    }

    private function registerResourceCapabilities(CapabilityStatementBuilder $builder): void
    {
        $defaultInteractions = ['read', 'search-type', 'create', 'update', 'delete'];

        $commonSearchParams = [
            new SearchParameter('_id', 'token', 'Resource ID'),
            new SearchParameter('_lastUpdated', 'date', 'Last updated date'),
        ];

        $patientSearchParams = [
            ...$commonSearchParams,
            new SearchParameter('name', 'string', 'A portion of the name'),
            new SearchParameter('gender', 'token', 'Gender of the patient'),
            new SearchParameter('birthdate', 'date', 'The date of birth'),
            new SearchParameter('identifier', 'token', 'A patient identifier'),
        ];

        $builder->addResource(ResourceType::Patient, $defaultInteractions, $patientSearchParams);

        $observationSearchParams = [
            ...$commonSearchParams,
            new SearchParameter('subject', 'reference', 'Subject of the observation'),
            new SearchParameter('code', 'token', 'Observation code'),
            new SearchParameter('date', 'date', 'Observation date'),
            new SearchParameter('status', 'token', 'Observation status'),
        ];

        $builder->addResource(ResourceType::Observation, $defaultInteractions, $observationSearchParams);

        $encounterSearchParams = [
            ...$commonSearchParams,
            new SearchParameter('subject', 'reference', 'Subject of the encounter'),
            new SearchParameter('status', 'token', 'Encounter status'),
            new SearchParameter('class', 'token', 'Classification of the encounter'),
            new SearchParameter('date', 'date', 'A date within the period'),
        ];

        $builder->addResource(ResourceType::Encounter, $defaultInteractions, $encounterSearchParams);
        $builder->addResource(ResourceType::Condition, $defaultInteractions, $commonSearchParams);
        $builder->addResource(ResourceType::MedicationRequest, $defaultInteractions, $commonSearchParams);
        $builder->addResource(ResourceType::AllergyIntolerance, $defaultInteractions, $commonSearchParams);
        $builder->addResource(ResourceType::Procedure, $defaultInteractions, $commonSearchParams);
        $builder->addResource(ResourceType::DiagnosticReport, $defaultInteractions, $commonSearchParams);
    }
}
