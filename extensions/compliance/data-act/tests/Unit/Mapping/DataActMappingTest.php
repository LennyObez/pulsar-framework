<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Extension\DataAct\Mapping\DataActMapping;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function sprintf;

/**
 * The EU Data Act obligations, assessed rather than asserted.
 *
 * Data access, sharing, cloud switching and interoperability are product and
 * contract obligations that no application framework can observe. Only the
 * condition on third-party processing rests on something the deployment does.
 */
#[CoversClass(DataActMapping::class)]
final class DataActMappingTest extends TestCase
{
    #[Test]
    public function declaresSixControls(): void
    {
        self::assertCount(6, DataActMapping::declarations());
    }

    #[Test]
    public function everyControlBelongsToTheDataActFramework(): void
    {
        foreach (DataActMapping::declarations() as $declaration) {
            self::assertSame(ComplianceFramework::DataAct, $declaration->framework);
        }
    }

    #[Test]
    public function everyUnprobedObligationNamesItsAssessorArtefact(): void
    {
        foreach (DataActMapping::declarations() as $declaration) {
            if ($declaration->isProbed()) {
                continue;
            }

            self::assertNotSame('', $declaration->operatorArtefact, $declaration->id);
        }
    }

    #[Test]
    public function thirdPartyAccessConditionsAreAGapWhenNoRouteIsClassified(): void
    {
        $finding = self::finding(
            'data-act-art-6',
            DeploymentUnderAssessment::withNothing([ComplianceFramework::DataAct]),
        );

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertSame('probe.access_restriction', $finding->probeId);
    }

    /**
     * Even a deployment whose every route carries its controls does not satisfy
     * Art 6, and the reason is worth stating rather than working around.
     *
     * Both facts the probe rests on — the classified-route coverage and the
     * wiring inspector's defect scan — are enumerations of the estate, at grade
     * Resolved. They say what is attached to a route and what the container
     * built; neither says that a third party was ever refused access it should
     * not have. ADR-0050 withdrew Resolved as proof, so the control is now
     * reported as claimed and not observed, naming what was enumerated.
     *
     * Closing it needs a probe that exercises a classified route and observes the
     * refusal. That is real work and it is not done here: writing something that
     * "exercises" a route by reading its middleware list back would be the defect
     * ADR-0041 was written about.
     */
    #[Test]
    public function thirdPartyAccessConditionsAreClaimedAndNotObservedEvenWithClassifiedRoutes(): void
    {
        $deployment = DeploymentUnderAssessment::fullyEquipped([ComplianceFramework::DataAct]);
        $finding = self::finding('data-act-art-6', $deployment);

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertStringContainsString('(resolved)', $finding->summary);
        self::assertNotSame([], $finding->remediations);
    }

    #[Test]
    public function cloudSwitchingIsNeverTheFrameworksToClaim(): void
    {
        $finding = self::finding(
            'data-act-art-25',
            DeploymentUnderAssessment::withNothing([ComplianceFramework::DataAct]),
        );

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertFalse($finding->outcome->countsTowardCoverage());
    }

    private static function finding(string $id, DeploymentUnderAssessment $deployment): ControlFinding
    {
        $catalog = new ControlCatalog();
        $catalog->register(...DataActMapping::declarations());

        foreach (new ControlAssessment($catalog)->assessAll($deployment->evidence()) as $finding) {
            if ($finding->declaration->id === $id) {
                return $finding;
            }
        }

        self::fail(sprintf('No Data Act control %s is declared.', $id));
    }
}
