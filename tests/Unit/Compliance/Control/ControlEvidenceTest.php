<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\IncompleteEvidenceException;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Tests\Support\Compliance\ReflectedVocabulary;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;

use function count;

/**
 * The frozen fact set, and the totality that makes it safe to read.
 */
#[CoversClass(ControlEvidence::class)]
#[CoversClass(IncompleteEvidenceException::class)]
#[CoversClass(ObservationId::class)]
final class ControlEvidenceTest extends TestCase
{
    #[Test]
    public function aCompleteSetIsAcceptedAndTotal(): void
    {
        $evidence = self::complete();

        self::assertCount(count(ObservationId::cases()), $evidence->all());

        foreach (ObservationId::cases() as $id) {
            self::assertSame($id, $evidence->observation($id)->id);
        }
    }

    /**
     * A gatherer that quietly stopped producing a fact must fail the whole report
     * loudly. The alternative is every probe that reads that fact concluding
     * something from nothing, one control at a time, with no symptom anywhere.
     */
    #[Test]
    public function anIncompleteSetIsRefusedAtConstruction(): void
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            if ($id === ObservationId::AuditChainVerified) {
                continue;
            }

            $observations[] = self::observation($id);
        }

        $this->expectException(IncompleteEvidenceException::class);
        $this->expectExceptionMessageMatches('/audit_chain_verified/');

        (void) ReflectedVocabulary::evidence(...$observations);
    }

    #[Test]
    public function theRefusalListsEveryFactThatWasNotGathered(): void
    {
        try {
            (void) ReflectedVocabulary::evidence(self::observation(ObservationId::MasterKeyResolved));

            self::fail('An almost-empty evidence set should have been refused.');
        } catch (IncompleteEvidenceException $exception) {
            self::assertCount(count(ObservationId::cases()) - 1, $exception->missing());
        }
    }

    /**
     * Later observations of the same fact replace earlier ones rather than
     * accumulating, so a probe always reads one answer per question.
     */
    #[Test]
    public function oneFactYieldsOneObservation(): void
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            $observations[] = self::observation($id, 'first');
        }

        $observations[] = self::observation(ObservationId::MasterKeyResolved, 'second');

        $evidence = ReflectedVocabulary::evidence(...$observations);

        self::assertCount(count(ObservationId::cases()), $evidence->all());
        self::assertSame('second', $evidence->observation(ObservationId::MasterKeyResolved)->detail);
    }

    private static function complete(): ControlEvidence
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            $observations[] = self::observation($id);
        }

        return ReflectedVocabulary::evidence(...$observations);
    }

    /**
     * @param non-empty-string $detail
     */
    private static function observation(ObservationId $id, string $detail = 'a fact'): Observation
    {
        return SyntheticObservation::of($id, ObservationGrade::Resolved, true, $detail);
    }
}
