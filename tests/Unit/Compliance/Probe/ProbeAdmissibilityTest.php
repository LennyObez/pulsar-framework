<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Probe;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ControlProbeInterface;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\ProbeVerdict;
use Pulsar\Compliance\Probe\CapabilityProbe;
use Pulsar\Tests\Support\Compliance\ReflectedVocabulary;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;
use ReflectionClass;

use function basename;
use function glob;
use function is_subclass_of;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * The total defence: EVERY probe in the tree, against evidence that proves nothing.
 *
 * Two deployments are simulated and neither may satisfy any control:
 *
 *  - one where every fact is absent, and
 *  - one where every fact is PRESENT but only at grade Declared — the deployment
 *    whose configuration says every control is on and where nothing was ever
 *    observed doing it.
 *
 * The second is the one that matters. It is the general form of ADR-0041's defect:
 * a control satisfied by what someone wanted rather than by what the deployment
 * does. This test is cheap, total, and fails the moment anyone adds a probe that
 * concludes from intent — including a probe nobody has wired into a mapping yet,
 * because it enumerates the directory rather than the catalog.
 */
#[CoversClass(CapabilityProbe::class)]
final class ProbeAdmissibilityTest extends TestCase
{
    #[Test]
    #[DataProvider('everyProbe')]
    public function noProbeIsSatisfiedByAnEmptyDeployment(ControlProbeInterface $probe): void
    {
        $verdict = ProbeVerdict::reach($probe->requirement(), self::evidenceWhereNothingHolds());

        self::assertNotSame(
            ControlOutcome::Satisfied,
            $verdict->outcome,
            sprintf('%s satisfied a control from a deployment that has nothing.', $probe->id()),
        );
    }

    #[Test]
    #[DataProvider('everyProbe')]
    public function noProbeIsSatisfiedByConfigurationAlone(ControlProbeInterface $probe): void
    {
        $verdict = ProbeVerdict::reach($probe->requirement(), self::evidenceThatIsOnlyConfigured());

        self::assertNotSame(
            ControlOutcome::Satisfied,
            $verdict->outcome,
            sprintf(
                '%s satisfied a control from configuration alone. A Declared observation records '
                    . 'what an operator asked for, never what the deployment did.',
                $probe->id(),
            ),
        );
    }

    /**
     * The second rule, and the one this file exists to make total: a deployment
     * where every fact PRESENT is a resolved class name — the container answered,
     * the class is on the allow-list, nothing was run — satisfies no control.
     *
     * `ObservationGrade::Resolved` used to prove behaviour, and a probe whose
     * required facts were all resolutions could therefore reach Satisfied on
     * "which class is bound, and is it on the allow-list". That is ADR-0041's
     * defect stated in the vocabulary built to forbid it. Nineteen of the probes
     * below were passing on it.
     */
    #[Test]
    #[DataProvider('everyProbe')]
    public function noProbeIsSatisfiedByResolvedIdentityAlone(ControlProbeInterface $probe): void
    {
        $verdict = ProbeVerdict::reach($probe->requirement(), self::evidenceThatOnlyResolves());

        self::assertNotSame(
            ControlOutcome::Satisfied,
            $verdict->outcome,
            sprintf(
                '%s satisfied a control from resolved identity alone. A Resolved observation '
                    . 'records which class answered a contract, never that anything ran.',
                $probe->id(),
            ),
        );
    }

    /**
     * A gap must always say how to close it, otherwise the report is a complaint.
     */
    #[Test]
    #[DataProvider('everyProbe')]
    public function everyProbeNamesARemediationWhenItReportsAGap(ControlProbeInterface $probe): void
    {
        $verdict = ProbeVerdict::reach($probe->requirement(), self::evidenceWhereNothingHolds());

        if ($verdict->outcome === ControlOutcome::NotApplicable) {
            return;
        }

        self::assertNotSame([], $verdict->remediations, $probe->id());
    }

    #[Test]
    #[DataProvider('everyProbe')]
    public function everyProbeHasAStableIdentifierAndADescription(ControlProbeInterface $probe): void
    {
        self::assertStringStartsWith('probe.', $probe->id());
        self::assertNotSame('', $probe->describe());
    }

    /**
     * Every concrete probe in `src/Compliance/Probe`, found by enumerating the
     * directory rather than the catalog: a probe that no mapping cites yet must
     * still be held to the rule, or the rule only applies to probes someone
     * remembered to wire up.
     *
     * @return iterable<string, array{ControlProbeInterface}>
     */
    public static function everyProbe(): iterable
    {
        $files = glob(__DIR__ . '/../../../../src/Compliance/Probe/*.php');

        self::assertIsArray($files);
        self::assertNotSame([], $files, 'No probes were found; the directory glob is wrong.');

        foreach ($files as $file) {
            $class = 'Pulsar\\Compliance\\Probe\\' . substr(basename($file), 0, -4);

            if (!is_subclass_of($class, ControlProbeInterface::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            /** @var ControlProbeInterface $probe */
            $probe = $reflection->newInstance();

            yield $probe->id() => [$probe];
        }
    }

    /**
     * Every fact absent, except the scope assertions: those stay in scope so each
     * probe actually runs its logic instead of short-circuiting to NotApplicable.
     */
    private static function evidenceWhereNothingHolds(): ControlEvidence
    {
        return self::evidence(present: false, grade: ObservationGrade::Resolved);
    }

    /**
     * Every fact present, and every one of them a config read.
     */
    private static function evidenceThatIsOnlyConfigured(): ControlEvidence
    {
        return self::evidence(present: true, grade: ObservationGrade::Declared);
    }

    /**
     * Every fact present, and every one of them the name of a class that is wired.
     */
    private static function evidenceThatOnlyResolves(): ControlEvidence
    {
        return self::evidence(present: true, grade: ObservationGrade::Resolved);
    }

    private static function evidence(bool $present, ObservationGrade $grade): ControlEvidence
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            $isScope = str_starts_with($id->value, 'scope_');

            $observations[] = SyntheticObservation::of(
                $id,
                $isScope ? ObservationGrade::Asserted : $grade,
                $isScope || $present,
                'synthetic observation for the admissibility test',
            );
        }

        return ReflectedVocabulary::evidence(...$observations);
    }
}
