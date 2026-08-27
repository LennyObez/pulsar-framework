<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Extension\Dsa\Mapping\DsaMapping;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function sprintf;

/**
 * The Digital Services Act obligations, assessed rather than asserted.
 *
 * Almost all of them are discharged in published documents and in the product —
 * a contact point, terms of service, a transparency report, a complaint-handling
 * system — none of which an application framework can observe. They name the
 * artefact an assessor should be shown. The one runtime-observable obligation is
 * that a statement of reasons, once issued, is retained provably.
 */
#[CoversClass(DsaMapping::class)]
final class DsaMappingTest extends TestCase
{
    private const string AUDIT_SINK = 'Pulsar\Security\Audit\AuditSinkInterface';

    #[Test]
    public function declaresTenControls(): void
    {
        self::assertCount(10, DsaMapping::declarations());
    }

    #[Test]
    public function everyControlBelongsToTheDsaFramework(): void
    {
        foreach (DsaMapping::declarations() as $declaration) {
            self::assertSame(ComplianceFramework::Dsa, $declaration->framework);
        }
    }

    /**
     * A control with no probe must name what an assessor is to be shown, or it is
     * a control quietly excluded from coverage and from view at the same time.
     */
    #[Test]
    public function everyUnprobedObligationNamesItsAssessorArtefact(): void
    {
        foreach (DsaMapping::declarations() as $declaration) {
            if ($declaration->isProbed()) {
                continue;
            }

            self::assertNotSame('', $declaration->operatorArtefact, $declaration->id);
        }
    }

    #[Test]
    public function statementsOfReasonsAreAGapWithoutARetainedAuditTrail(): void
    {
        $finding = self::finding(
            'dsa-art-17',
            DeploymentUnderAssessment::withNothing([ComplianceFramework::Dsa]),
        );

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertSame('probe.tamper_evident_audit', $finding->probeId);
    }

    #[Test]
    public function statementsOfReasonsAreSatisfiedWhenTheTrailIsDurableAndVerifiable(): void
    {
        $deployment = DeploymentUnderAssessment::withNothing([ComplianceFramework::Dsa])
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->withVerifiedEvidenceChain();

        self::assertSame(ControlOutcome::Satisfied, self::finding('dsa-art-17', $deployment)->outcome);
    }

    #[Test]
    public function theSystemicRiskAssessmentIsNeverTheFrameworksToClaim(): void
    {
        $finding = self::finding(
            'dsa-art-34',
            DeploymentUnderAssessment::withNothing([ComplianceFramework::Dsa]),
        );

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertFalse($finding->outcome->countsTowardCoverage());
    }

    private static function finding(string $id, DeploymentUnderAssessment $deployment): ControlFinding
    {
        $catalog = new ControlCatalog();
        $catalog->register(...DsaMapping::declarations());

        foreach (new ControlAssessment($catalog)->assessAll($deployment->evidence()) as $finding) {
            if ($finding->declaration->id === $id) {
                return $finding;
            }
        }

        self::fail(sprintf('No DSA control %s is declared.', $id));
    }
}
