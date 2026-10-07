<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Fhir\Audit\FhirAuditEventMapper;
use Pulsar\Extension\Fhir\Config\FhirConfig;
use Pulsar\Extension\Fhir\FhirServiceProvider;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\FhirController;
use Pulsar\Extension\Fhir\Rest\FhirRepositoryInterface;
use Pulsar\Extension\Fhir\Smart\SmartScopeEnforcer;
use Pulsar\Extension\Fhir\Terminology\TerminologyServiceInterface;
use Pulsar\Extension\Fhir\Terminology\ValueSetValidator;

#[CoversClass(FhirServiceProvider::class)]
final class FhirServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsAllServices(): void
    {
        $registered = [];
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(8))
            ->method('instance')
            ->willReturnCallback(function (string $id) use (&$registered): void {
                $registered[] = $id;
            });

        $provider = new FhirServiceProvider();
        $provider->register($container);

        self::assertContains(FhirConfig::class, $registered);
        self::assertContains(FhirRepositoryInterface::class, $registered);
        self::assertContains(CapabilityStatementBuilder::class, $registered);
        self::assertContains(FhirController::class, $registered);
        self::assertContains(ValueSetValidator::class, $registered);
        self::assertContains(TerminologyServiceInterface::class, $registered);
        self::assertContains(SmartScopeEnforcer::class, $registered);
        self::assertContains(FhirAuditEventMapper::class, $registered);
    }

    #[Test]
    public function providesListsAllServices(): void
    {
        $provider = new FhirServiceProvider();
        $provides = $provider->provides();

        self::assertContains(FhirConfig::class, $provides);
        self::assertContains(FhirRepositoryInterface::class, $provides);
        self::assertContains(CapabilityStatementBuilder::class, $provides);
        self::assertContains(FhirController::class, $provides);
        self::assertContains(ValueSetValidator::class, $provides);
        self::assertContains(TerminologyServiceInterface::class, $provides);
        self::assertContains(SmartScopeEnforcer::class, $provides);
        self::assertContains(FhirAuditEventMapper::class, $provides);
    }
}
