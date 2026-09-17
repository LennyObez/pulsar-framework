<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Throwable;

use function abs;
use function count;
use function implode;
use function sprintf;
use function str_contains;

/**
 * Whether this deployment's AI management system record survives the process
 * that wrote it, or only exists inside it.
 *
 * WHY THIS EXISTS. Thirteen ISO 42001 controls rested on facts of the form "which
 * class answered `AiModelRegistryInterface`", and the four classes the extension
 * shipped were `InMemoryModelRegistry`, `InMemoryImpactAssessmentStore`,
 * `InMemoryExplainabilityStore` and `InMemoryDataGovernanceStore`. The framework's
 * own documentation described them exactly right — *usable for development, and
 * evidence of nothing after a restart* — and the report's only way of knowing that
 * was a hard-coded list of class names in {@see ControlEvidenceGatherer}, which
 * grades a store by RECOGNISING it. A store that list has not been told about is
 * graded as fine, and a store it has been told about is graded as inert even if
 * someone made it durable. Both directions are wrong for the same reason: nothing
 * looked at what the store did.
 *
 * WHAT IT RUNS, all of it against the stores that serve the deployment:
 *
 *   1. a model is registered, and read back through a SECOND store instance
 *   2. what came back is the record that was written, field for field
 *   3. an impact assessment is opened, and read back through a second instance
 *   4. a data quality report is written, and read back through a second instance
 *      with its numbers intact
 *   5. an explanation is recorded, and read back through a second instance with
 *      its decision factor intact — an explanation whose prose survives and whose
 *      factors do not cannot answer "why", which is what the control is for
 *   6. the residue this check leaves is bounded (see below)
 *
 * THE SECOND INSTANCE IS THE MEASUREMENT. It shares no memory with the instance
 * that wrote, so a record held in a PHP array is not on the path back. That single
 * property is what separates a store that retains from one that remembers, and it
 * does it without knowing any class's name. Where the deployment configured a
 * store by class name the drill cannot build a second copy, reports
 * `fresh_instance: false`, and the subject FAILS — not because the store is
 * assumed bad, but because a read the writer's own memory can serve establishes
 * nothing, and this vocabulary does not have a word for "probably fine".
 *
 * THIS MEASUREMENT WRITES, and the residue is bounded structurally because it
 * cannot be withdrawn: none of the four contracts has a removal. Every write here
 * goes under a reserved identifier — {@see PROBE_MODEL}, {@see PROBE_DATASET},
 * {@see PROBE_DECISION} — and every one of the four stores is keyed by the
 * identifier its own DTO documents as unique, so a second run REPLACES what the
 * first one left. Subject 6 establishes that by execution rather than by
 * assertion: the model is registered a second time and the inventory must be the
 * size it already was. What this check leaves behind, for the life of the store,
 * is one model, one assessment, one quality report and one explanation — and it
 * says so in the detail line on the passing branch too, because a note that only
 * appears when something goes wrong is a note nobody reads.
 *
 * WHAT IT DELIBERATELY DOES NOT WRITE: a provenance record.
 * `AiDataGovernanceInterface` accumulates provenance per record with no
 * withdrawal, so a compliance report would grow the deployment's governance record
 * by a row per run forever. The quality report exercises the same store and is
 * keyed by dataset.
 *
 * ABSENT IS NOT FALSE. The `pulsar/ai-governance` extension is trust tier
 * `verified` and kind `product`: it does not load unless an operator enables it,
 * and a deployment without it binds no {@see AiGovernanceDrillInterface}. This
 * observer is then handed null and reports {@see Measurement::couldNotRun()} — a
 * gap, because enabling ISO 42001 in `config/compliance.php` IS the operator's
 * assertion that the deployment must satisfy it. It is deliberately NOT
 * {@see Observation::noSubject()}, which would retire the controls instead of
 * failing them.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiGovernanceRecordObserver
{
    /**
     * The model this check registers.
     *
     * Reserved, namespaced to the compliance subsystem, classified `minimal` and
     * held at `development`. The tier is the measurement's own restraint: a
     * probe model classified `high` would put a fictitious high-risk system into
     * an inventory an auditor reads, and one classified `unacceptable` would be
     * refused registration by every conforming implementation — which would
     * measure the refusal rather than the record.
     */
    public const string PROBE_MODEL = 'compliance.ai_governance_probe/model';

    /** The dataset the quality report is written against. Routed to by nothing. */
    public const string PROBE_DATASET = 'compliance.ai_governance_probe/dataset';

    /** The decision the explanation is recorded against. Reached by no request. */
    public const string PROBE_DECISION = 'compliance.ai_governance_probe/decision';

    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live AI management system record';

    private const string PROBE_MODEL_NAME = 'Compliance probe model';

    private const string PROBE_MODEL_VERSION = '1.0.0';

    private const string PROBE_MODEL_PROVIDER = 'pulsar/compliance';

    private const string PROBE_MODEL_TYPE = 'classifier';

    private const string PROBE_RISK_LEVEL = 'minimal';

    private const string PROBE_STATUS = 'development';

    /**
     * The instant every record this check writes is stamped with.
     *
     * 2026-08-02T00:00:00Z, fixed rather than read from a clock, and the choice is
     * the measurement: a store that stamped its own clock over the value it was
     * handed comes back with a different number here. Governance records are
     * evidence about when something happened, and a store that overwrites the
     * "when" is recording the time of storage.
     */
    private const int PROBE_INSTANT = 1_785_628_800;

    private const string PROBE_SUMMARY = 'A compliance probe decision, explained so that the '
        . 'explainability record can be read back and checked.';

    private const string PROBE_FACTOR = 'compliance_probe_factor';

    private const string PROBE_FACTOR_DESCRIPTION = 'The single factor this probe records, so that '
        . 'a store which keeps the prose and drops the structure is visible.';

    private const float PROBE_CONFIDENCE = 0.75;

    private const float PROBE_FACTOR_WEIGHT = 0.5;

    private const float PROBE_COMPLETENESS = 99.5;

    private const float PROBE_ACCURACY = 98.25;

    private const float PROBE_CONSISTENCY = 97.0;

    private const int PROBE_TOTAL_RECORDS = 1000;

    private const int PROBE_INVALID_RECORDS = 7;

    /** How far two stored floats may differ and still be the same number. */
    private const float TOLERANCE = 0.001;

    /**
     * The phrase that marks a subject which could not be established rather than
     * one that was established and failed.
     *
     * A constant because it is written in one place and read in another: the
     * summary has to carry the distinction, or a report reader looking at a
     * third-party store would see "the model inventory outlives ... failed" and
     * conclude the store is broken. It is not; nothing looked.
     */
    private const string NOT_ESTABLISHED = 'cannot construct a second instance of';

    /**
     * Exercise the governance record, or report that there was none to exercise.
     *
     * @param AiGovernanceDrillInterface|null $drill The seam the deployment bound,
     *        resolved by the composition root. Null when no extension answered it —
     *        a fact about this deployment, not a pass and not a silence
     */
    #[NoDiscard]
    public function observe(?AiGovernanceDrillInterface $drill): Observation
    {
        if ($drill === null) {
            return Observation::measured(
                ObservationId::AiGovernanceRecordsDurable,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No AI governance subsystem is in service, so no record was written or read '
                        . 'back: this deployment holds no AI system inventory, no impact assessment, '
                        . 'no data governance record and no explanation, and ISO 42001 Clause 7.5 '
                        . 'asks for all four as documented information.',
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::AiGovernanceRecordsDurable,
            self::exercise($drill),
            self::class,
        );
    }

    /**
     * Run the subjects against the stores that serve the deployment.
     *
     * A throw from the first registration is reported as a subject that RAN and
     * failed, not as a run that could not happen: the store was called and it
     * refused, which is the failure a bound-but-unusable store falls on.
     */
    private static function exercise(AiGovernanceDrillInterface $drill): Measurement
    {
        $stores = $drill->configuredStores();

        try {
            self::writeProbeModel($drill);
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'a model can be registered',
                    sprintf(
                        'The model registry refused a coherent inventory entry: %s. Nothing in this '
                            . 'deployment can record which AI systems are in its scope.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf(
                    'The AI governance record is bound but not usable: registering a model failed '
                        . 'with %s.',
                    $failure->getMessage(),
                ),
            );
        }

        $inventoryBefore = $drill->readModelBack(self::PROBE_MODEL)['inventory_size'];

        $results = [
            self::modelSurvives($drill, $stores['registry']),
            self::assessmentSurvives($drill, $stores['impact_assessment']),
            self::qualityReportSurvives($drill, $stores['data_governance']),
            self::explanationSurvives($drill, $stores['explainability']),
            self::residueIsBounded($drill, $inventoryBefore),
        ];

        return Measurement::completed(self::SUBJECT, $results, self::describe($results, $stores));
    }

    private static function writeProbeModel(AiGovernanceDrillInterface $drill): void
    {
        $drill->recordModel(
            self::PROBE_MODEL,
            self::PROBE_MODEL_NAME,
            self::PROBE_MODEL_VERSION,
            self::PROBE_MODEL_PROVIDER,
            self::PROBE_MODEL_TYPE,
            self::PROBE_RISK_LEVEL,
            self::PROBE_STATUS,
            self::PROBE_INSTANT,
        );
    }

    /**
     * The inventory entry must be there when a store that did not write it looks.
     */
    private static function modelSurvives(AiGovernanceDrillInterface $drill, string $store): ExecutedSubject
    {
        $name = 'the model inventory outlives the instance that wrote it';

        try {
            $read = $drill->readModelBack(self::PROBE_MODEL);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed($name, sprintf(
                'The registry refused to return the model it had just been given: %s.',
                $failure->getMessage(),
            ));
        }

        if (! $read['fresh_instance']) {
            return self::noSecondInstance($name, 'model registry', $store);
        }

        $record = $read['record'];

        if ($record === null) {
            return ExecutedSubject::failed($name, sprintf(
                'A registry instance that did not write the model holds nothing for it, so the '
                    . 'inventory this deployment keeps (%s) exists only inside the process that '
                    . 'registered it. ISO 42001 Clause 7.5 asks for documented information, and a '
                    . 'record that a second reader cannot see is not documented.',
                $store,
            ));
        }

        $wrong = [];

        if ($record['id'] !== self::PROBE_MODEL) {
            $wrong[] = sprintf('it came back under id "%s"', $record['id']);
        }

        if ($record['name'] !== self::PROBE_MODEL_NAME) {
            $wrong[] = 'the name is not the one that was registered';
        }

        if ($record['version'] !== self::PROBE_MODEL_VERSION) {
            $wrong[] = 'the version is not the one that was registered';
        }

        if ($record['provider'] !== self::PROBE_MODEL_PROVIDER) {
            $wrong[] = 'the provider is not the one that was registered';
        }

        if ($record['type'] !== self::PROBE_MODEL_TYPE) {
            $wrong[] = 'the model type is not the one that was registered';
        }

        if ($record['risk_level'] !== self::PROBE_RISK_LEVEL) {
            $wrong[] = sprintf(
                'the risk classification reads "%s" rather than "%s", so the tier that decides '
                    . 'every EU AI Act obligation did not survive storage',
                $record['risk_level'],
                self::PROBE_RISK_LEVEL,
            );
        }

        if ($record['status'] !== self::PROBE_STATUS) {
            $wrong[] = sprintf('the lifecycle status reads "%s"', $record['status']);
        }

        if ($record['registered_at'] !== self::PROBE_INSTANT) {
            $wrong[] = 'the registration instant is not the one that was handed in, so the store '
                . 'stamped its own clock over the time the record is evidence about';
        }

        return $wrong === []
            ? ExecutedSubject::passed($name, sprintf(
                'A model registered through the %s store was read back, intact and field for field, '
                    . 'through a registry instance that shares no memory with the one that wrote it.',
                $store,
            ))
            : ExecutedSubject::failed($name, sprintf(
                'The inventory entry that came back is not the one that was registered — %s.',
                implode('; ', $wrong),
            ));
    }

    /**
     * "Assessed with nothing adverse found" must be distinguishable from "never
     * assessed" by a reader that did not perform the assessment.
     */
    private static function assessmentSurvives(AiGovernanceDrillInterface $drill, string $store): ExecutedSubject
    {
        $name = 'an impact assessment outlives the instance that recorded it';

        try {
            $drill->openAssessment(self::PROBE_MODEL);
            $read = $drill->readAssessmentBack(self::PROBE_MODEL);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed($name, sprintf(
                'The impact assessment store refused to record or return an assessment: %s.',
                $failure->getMessage(),
            ));
        }

        if (! $read['fresh_instance']) {
            return self::noSecondInstance($name, 'impact assessment store', $store);
        }

        if (! $read['assessed']) {
            return ExecutedSubject::failed($name, sprintf(
                'A store instance that did not record the assessment reports the model as never '
                    . 'assessed, so the %s store cannot tell "assessed, nothing adverse found" from '
                    . '"never looked". EU AI Act Article 9 and ISO 42001 Clause 6.1.2 both turn on '
                    . 'that distinction, and the high-risk deployment gate reads it directly.',
                $store,
            ));
        }

        return ExecutedSubject::passed($name, sprintf(
            'An assessment opened through the %s store is still on record when a store instance '
                . 'that did not open it asks, with %d finding(s) and a computed risk score of %.2f.',
            $store,
            $read['findings'],
            $read['risk_score'],
        ));
    }

    /**
     * The data quality record must survive with its numbers, not merely its key.
     */
    private static function qualityReportSurvives(AiGovernanceDrillInterface $drill, string $store): ExecutedSubject
    {
        $name = 'the data governance record outlives the instance that wrote it';

        try {
            $drill->recordQualityReport(
                self::PROBE_DATASET,
                self::PROBE_INSTANT,
                self::PROBE_COMPLETENESS,
                self::PROBE_ACCURACY,
                self::PROBE_CONSISTENCY,
                self::PROBE_TOTAL_RECORDS,
                self::PROBE_INVALID_RECORDS,
            );

            $read = $drill->readQualityReportBack(self::PROBE_DATASET);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed($name, sprintf(
                'The data governance store refused to record or return a quality report: %s.',
                $failure->getMessage(),
            ));
        }

        if (! $read['fresh_instance']) {
            return self::noSecondInstance($name, 'data governance store', $store);
        }

        $report = $read['report'];

        if ($report === null) {
            return ExecutedSubject::failed($name, sprintf(
                'A store instance that did not write the report holds nothing for the dataset, so '
                    . 'what the %s store knows about training data provenance and quality is gone '
                    . 'with the process that recorded it. ISO 42001 Clause 8.3 asks for that record '
                    . 'to exist after the fact.',
                $store,
            ));
        }

        $wrong = [];

        if ($report['dataset_id'] !== self::PROBE_DATASET) {
            $wrong[] = sprintf('it came back under dataset "%s"', $report['dataset_id']);
        }

        if (abs($report['completeness'] - self::PROBE_COMPLETENESS) > self::TOLERANCE) {
            $wrong[] = 'the completeness measure is not the one that was recorded';
        }

        if (abs($report['accuracy'] - self::PROBE_ACCURACY) > self::TOLERANCE) {
            $wrong[] = 'the accuracy measure is not the one that was recorded';
        }

        if (abs($report['consistency'] - self::PROBE_CONSISTENCY) > self::TOLERANCE) {
            $wrong[] = 'the consistency measure is not the one that was recorded';
        }

        if ($report['total_records'] !== self::PROBE_TOTAL_RECORDS
            || $report['invalid_records'] !== self::PROBE_INVALID_RECORDS
        ) {
            $wrong[] = 'the record counts are not the ones that were recorded';
        }

        if ($report['assessed_at'] !== self::PROBE_INSTANT) {
            $wrong[] = 'the assessment instant is not the one that was handed in';
        }

        return $wrong === []
            ? ExecutedSubject::passed($name, sprintf(
                'A data quality report written through the %s store was read back with every measure '
                    . 'intact by an instance that did not write it.',
                $store,
            ))
            : ExecutedSubject::failed($name, sprintf(
                'The quality report that came back is not the one that was written — %s.',
                implode('; ', $wrong),
            ));
    }

    /**
     * The explanation must survive with the factors that make it an explanation.
     */
    private static function explanationSurvives(AiGovernanceDrillInterface $drill, string $store): ExecutedSubject
    {
        $name = 'a decision explanation outlives the instance that recorded it';

        try {
            $drill->recordExplanation(
                self::PROBE_DECISION,
                self::PROBE_MODEL,
                self::PROBE_SUMMARY,
                self::PROBE_CONFIDENCE,
                self::PROBE_INSTANT,
                self::PROBE_FACTOR,
                self::PROBE_FACTOR_WEIGHT,
                self::PROBE_FACTOR_DESCRIPTION,
            );

            $read = $drill->readExplanationBack(self::PROBE_DECISION);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed($name, sprintf(
                'The explainability store refused to record or return an explanation: %s.',
                $failure->getMessage(),
            ));
        }

        if (! $read['fresh_instance']) {
            return self::noSecondInstance($name, 'explainability store', $store);
        }

        $explanation = $read['explanation'];

        if ($explanation === null) {
            return ExecutedSubject::failed($name, sprintf(
                'A store instance that did not record the explanation holds nothing for the '
                    . 'decision, so the %s store loses it with the request that produced it. A data '
                    . 'subject contesting an automated decision asks days later, and ISO 42001 '
                    . 'Annex A.8.5 exists for that moment.',
                $store,
            ));
        }

        $wrong = [];

        if ($explanation['summary'] !== self::PROBE_SUMMARY) {
            $wrong[] = 'the summary is not the one that was recorded';
        }

        if ($explanation['model_id'] !== self::PROBE_MODEL) {
            $wrong[] = 'it no longer names the model that made the decision';
        }

        if (abs($explanation['confidence'] - self::PROBE_CONFIDENCE) > self::TOLERANCE) {
            $wrong[] = 'the confidence score is not the one that was recorded';
        }

        if ($explanation['generated_at'] !== self::PROBE_INSTANT) {
            $wrong[] = 'the generation instant is not the one that was handed in';
        }

        if (count($explanation['factors']) !== 1) {
            $wrong[] = sprintf(
                'it came back with %d decision factor(s) rather than the one that was recorded, so '
                    . 'the structured half of the explanation did not survive',
                count($explanation['factors']),
            );
        } else {
            $factor = $explanation['factors'][0];

            if ($factor['name'] !== self::PROBE_FACTOR
                || $factor['description'] !== self::PROBE_FACTOR_DESCRIPTION
                || abs($factor['weight'] - self::PROBE_FACTOR_WEIGHT) > self::TOLERANCE
            ) {
                $wrong[] = 'the decision factor came back altered, so what the explanation says '
                    . 'influenced the decision is not what was recorded';
            }
        }

        return $wrong === []
            ? ExecutedSubject::passed($name, sprintf(
                'An explanation recorded through the %s store came back whole — summary, model, '
                    . 'confidence and its decision factor — read by an instance that did not record '
                    . 'it, and it is one of %d this deployment holds for that model.',
                $store,
                $read['by_model'],
            ))
            : ExecutedSubject::failed($name, sprintf(
                'The explanation that came back is not the one that was recorded — %s.',
                implode('; ', $wrong),
            ));
    }

    /**
     * What this check leaves behind must not grow by a run.
     *
     * Established by execution, not by reasoning about keys: the model is
     * registered a second time and the inventory must be exactly the size it
     * already was. A registry that appended would show it here, on the second
     * write in the same run, rather than after a year of compliance reports.
     */
    private static function residueIsBounded(AiGovernanceDrillInterface $drill, int $before): ExecutedSubject
    {
        $name = 'this check leaves one record and not one per run';

        try {
            self::writeProbeModel($drill);
            $after = $drill->readModelBack(self::PROBE_MODEL)['inventory_size'];
        } catch (Throwable $failure) {
            return ExecutedSubject::failed($name, sprintf(
                'The registry refused a second registration of the reserved probe model, so the '
                    . 'residue of this check could not be bounded by execution: %s.',
                $failure->getMessage(),
            ));
        }

        return $after === $before
            ? ExecutedSubject::passed($name, sprintf(
                'Registering the reserved probe model a second time left the inventory at %d '
                    . 'entries, so every compliance run replaces what the last one wrote rather '
                    . 'than adding to it.',
                $after,
            ))
            : ExecutedSubject::failed($name, sprintf(
                'The inventory grew from %d to %d when the same reserved model id was registered '
                    . 'twice, so the registry appends where its contract says a model id is unique '
                    . 'and every compliance run would enlarge this deployment\'s AI system inventory.',
                $before,
                $after,
            ));
    }

    /**
     * The read was served by the instance that wrote, and that establishes nothing.
     */
    private static function noSecondInstance(string $name, string $subsystem, string $store): ExecutedSubject
    {
        return ExecutedSubject::failed($name, sprintf(
            'This deployment configured its %s as "%s", a store this framework %s, so the read was '
                . 'served by the very object that performed the write. That establishes only that '
                . 'the object has memory. Durability of this record is real or not independently of '
                . 'what was measured here, and it has to be evidenced another way.',
            $subsystem,
            $store,
            self::NOT_ESTABLISHED,
        ));
    }

    /**
     * @param list<ExecutedSubject>                                                                             $results
     * @param array{registry: string, impact_assessment: string, data_governance: string, explainability: string, monitoring_records: string} $stores
     *
     * @return non-empty-string
     */
    private static function describe(array $results, array $stores): string
    {
        $failed = [];
        $notEstablished = false;

        foreach ($results as $result) {
            if (! $result->passed) {
                $failed[] = $result->name;

                if (str_contains($result->detail, self::NOT_ESTABLISHED)) {
                    $notEstablished = true;
                }
            }
        }

        // Said in the summary and not only in the subject, because the summary is
        // what a report reader sees first and "failed" reads as "broken".
        $reason = $notEstablished
            ? ' At least one of them was not established rather than disproved: this framework '
                . self::NOT_ESTABLISHED . ' a store configured by class name, so nothing looked.'
            : '';

        $residue = sprintf(
            ' It leaves behind one model (%s), one assessment against it, one data quality report '
                . '(%s) and one explanation (%s), each under a reserved identifier that the next run '
                . 'replaces.',
            self::PROBE_MODEL,
            self::PROBE_DATASET,
            self::PROBE_DECISION,
        );

        $configured = sprintf(
            ' Stores in service: registry=%s, impact assessment=%s, data governance=%s, '
                . 'explainability=%s.',
            $stores['registry'],
            $stores['impact_assessment'],
            $stores['data_governance'],
            $stores['explainability'],
        );

        if ($failed === []) {
            return sprintf(
                'Every record this deployment keeps about its AI systems was written and read back '
                    . 'through a store instance that did not write it, so the AI management system '
                    . 'record outlives the process.%s%s',
                $configured,
                $residue,
            );
        }

        return sprintf(
            '%d of %d subjects failed: %s. The AI management system record is not established as '
                . 'surviving the process that writes it, so what an assessor would be shown is '
                . 'whatever this worker happens to remember.%s%s%s',
            count($failed),
            count($results),
            implode('; ', $failed),
            $reason,
            $configured,
            $residue,
        );
    }
}
