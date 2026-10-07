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

use function count;
use function implode;
use function sprintf;

/**
 * Whether anything actually monitors this deployment's AI systems, and whether
 * the result of the monitoring is retained.
 *
 * WHY THIS EXISTS. ISO 42001 Clause 9.1 was carried by
 * `ai_monitoring_hook_resolved` — the question of whether the container could
 * answer `MonitoringHookInterface` — and that interface had ZERO implementations
 * anywhere in the tree. The interface, the registry that holds hooks, and one
 * test double were all there was, so Clause 9.1, Annex A.7 and Clause 10.1 could
 * not be satisfied by any deployment this framework could build. That was honest
 * and it was inert, which is the same class of broken instrument ADR-0062 named:
 * a control stuck in one direction.
 *
 * WHAT THE CLAUSE ACTUALLY ASKS, because building to the interface's shape alone
 * is what produced the gap. Clause 9.1 asks an organisation to determine what is
 * monitored, by which methods, when the monitoring happens and when the results
 * are analysed — and then it closes: *the organization shall retain appropriate
 * documented information as evidence of the results*. Running a hook discharges
 * the middle of that and none of its end. A deployment that monitored
 * continuously and dropped every result would present an auditor with exactly
 * what a deployment that never monitored presents: nothing. So this measurement
 * is in two halves, and both must hold.
 *
 * WHAT IT RUNS, against the subsystem that serves the deployment:
 *
 *   1. at least one monitoring hook answers when a model is monitored — the fact
 *      that was missing outright, and the one an integrator most often assumes
 *   2. every hook that answered named itself and said something about the model,
 *      so a hook that returns an empty verdict is not counted as monitoring
 *   3. the results were RETAINED, and are readable through a store instance that
 *      did not write them — Clause 9.1's closing sentence, measured the way
 *      {@see AiGovernanceRecordObserver} measures the rest of the record
 *   4. what came back is what ran: the same hooks, in the same number
 *
 * THIS MEASUREMENT WRITES AND THEN TAKES IT BACK. Unlike a declaration or an
 * inventory entry, a monitoring record is an EVENT: a second run appends a second
 * one, because the sequence is the evidence. Left alone, a compliance report would
 * grow the deployment's monitoring history by a row per run about a model that
 * serves no traffic. So the records this check wrote are purged after they have
 * been read back, through {@see AiGovernanceDrillInterface::purgeRetainedMonitoring()},
 * which is scoped to one model and cannot empty the register. The removal is
 * reported in the detail line on the passing branch too, and the count removed is
 * checked against the count written — a store that reported removing nothing is a
 * store that left residue.
 *
 * WHAT THIS DOES NOT SAY. It does not say the deployment's monitoring plan is
 * adequate: whether the right things are monitored, against the right thresholds,
 * at the right interval is a judgement about a plan, and no container can read
 * one. It says a hook exists, it ran, and its result was kept — which is the
 * floor Clause 9.1 sets and which no deployment of this framework could reach
 * before.
 *
 * ABSENT IS NOT FALSE. With no AI governance extension bound, the observer is
 * handed null and reports {@see Measurement::couldNotRun()} — a gap, because
 * enabling ISO 42001 in `config/compliance.php` is the operator's assertion that
 * the standard applies to this deployment.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiMonitoringObserver
{
    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live AI monitoring subsystem';

    /**
     * Exercise the monitoring subsystem, or report that there was none.
     *
     * @param AiGovernanceDrillInterface|null $drill The seam the deployment bound,
     *        resolved by the composition root
     */
    #[NoDiscard]
    public function observe(?AiGovernanceDrillInterface $drill): Observation
    {
        if ($drill === null) {
            return Observation::measured(
                ObservationId::AiMonitoringExercised,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No AI governance subsystem is in service, so no monitoring hook ran and no '
                        . 'monitoring result was retained. ISO 42001 Clause 9.1 asks for both, and '
                        . 'this deployment can produce neither.',
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::AiMonitoringExercised,
            self::exercise($drill),
            self::class,
        );
    }

    private static function exercise(AiGovernanceDrillInterface $drill): Measurement
    {
        // The model monitoring runs against is the one the record observer
        // registers, under the same reserved id and by the same upsert, so the two
        // checks share one inventory entry rather than leaving two. Registered here
        // as well because the gatherer's ordering is not this class's to assume:
        // a monitoring check that depended on another observer having run first
        // would fail for a reason that is a property of the report, not of the
        // deployment.
        try {
            $drill->recordModel(
                AiGovernanceRecordObserver::PROBE_MODEL,
                'Compliance probe model',
                '1.0.0',
                'pulsar/compliance',
                'classifier',
                'minimal',
                'development',
                1_785_628_800,
            );
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'a model can be put under monitoring',
                    sprintf(
                        'The registry refused the model this check monitors: %s. Nothing could be '
                            . 'monitored because nothing could be registered.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf('Monitoring could not be exercised: registering a model failed with %s.', $failure->getMessage()),
            );
        }

        $retainedBefore = self::retainedCount($drill);

        try {
            $results = $drill->runMonitoring(AiGovernanceRecordObserver::PROBE_MODEL);
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'a monitoring hook answers',
                    sprintf(
                        'Monitoring the model raised %s, so this deployment cannot run the checks it '
                            . 'has registered.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf('Monitoring is bound but not usable: running the hooks failed with %s.', $failure->getMessage()),
            );
        }

        if ($results === []) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'a monitoring hook answers',
                    'Monitoring a registered model ran no hook at all, so nothing in this deployment '
                        . 'watches a model once it is in service. ISO 42001 Clause 9.1 asks what is '
                        . 'monitored and by which method; the answer here is nothing, by none.',
                )],
                'No monitoring hook is registered, so nothing observes a deployed model and Clause 9.1 '
                    . 'has no result to retain.',
            );
        }

        $subjects = [
            self::hooksAnswered($results),
            self::resultsAreSubstantive($results),
        ];

        $subjects[] = self::resultsWereRetained($drill, $results, $retainedBefore);
        $subjects[] = self::residueIsRemoved($drill, count($results), $retainedBefore);

        return Measurement::completed(self::SUBJECT, $subjects, self::describe($subjects, $results));
    }

    /**
     * At least one hook answered, and this is the fact that did not exist.
     *
     * @param list<array{hook: string, healthy: bool, message: string, metrics: array<string, mixed>}> $results
     */
    private static function hooksAnswered(array $results): ExecutedSubject
    {
        $names = [];

        foreach ($results as $result) {
            $names[] = $result['hook'];
        }

        return ExecutedSubject::passed(
            'a monitoring hook answers',
            sprintf(
                '%d monitoring hook(s) ran against a registered model and returned a result: %s.',
                count($results),
                implode(', ', $names),
            ),
        );
    }

    /**
     * A result must say who produced it and what it found.
     *
     * A hook that returns a nameless or wordless result cannot be cited in a
     * Clause 9.1 record: the clause asks for evidence OF THE RESULTS, and a row
     * naming no method and carrying no finding is a row that evidences the
     * existence of a row.
     *
     * @param list<array{hook: string, healthy: bool, message: string, metrics: array<string, mixed>}> $results
     */
    private static function resultsAreSubstantive(array $results): ExecutedSubject
    {
        $name = 'each result names its method and what it found';
        $empty = [];
        $measured = 0;

        foreach ($results as $index => $result) {
            if ($result['hook'] === '' || $result['message'] === '') {
                $empty[] = $result['hook'] === ''
                    ? sprintf('the hook at position %d named itself with an empty string', $index)
                    : sprintf('hook "%s" returned an empty finding', $result['hook']);
            }

            $measured += count($result['metrics']);
        }

        return $empty === []
            ? ExecutedSubject::passed($name, sprintf(
                'Every result names the hook that produced it and states what it found, carrying %d '
                    . 'measured value(s) between them.',
                $measured,
            ))
            : ExecutedSubject::failed($name, sprintf(
                'A monitoring result cannot be cited as evidence of a result — %s.',
                implode('; ', $empty),
            ));
    }

    /**
     * The results must be retained, and readable by something that did not write
     * them.
     *
     * @param list<array{hook: string, healthy: bool, message: string, metrics: array<string, mixed>}> $results
     */
    private static function resultsWereRetained(
        AiGovernanceDrillInterface $drill,
        array $results,
        int $before,
    ): ExecutedSubject {
        $name = 'the results are retained as documented information';

        try {
            $read = $drill->readRetainedMonitoringBack(AiGovernanceRecordObserver::PROBE_MODEL);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed($name, sprintf(
                'The monitoring record store refused to return what was retained: %s.',
                $failure->getMessage(),
            ));
        }

        if (! $read['fresh_instance']) {
            return ExecutedSubject::failed($name, sprintf(
                'This deployment configured its monitoring record store as "%s", which this '
                    . 'framework cannot construct a second instance of, so the read was served by '
                    . 'the object that performed the write. That establishes only that the object '
                    . 'has memory, and Clause 9.1 asks for retained documented information.',
                $read['store'],
            ));
        }

        $expected = $before + count($results);

        if ($read['retained'] < $expected) {
            return ExecutedSubject::failed($name, sprintf(
                'A store instance that did not write them holds %d monitoring record(s) where %d '
                    . 'were expected, so the %s store did not retain what the hooks returned. '
                    . 'ISO 42001 Clause 9.1 requires documented information as evidence of the '
                    . 'monitoring results, and results that leave with the process are not evidence '
                    . 'of anything.',
                $read['retained'],
                $expected,
                $read['store'],
            ));
        }

        $retainedHooks = [];

        foreach ($read['records'] as $record) {
            $retainedHooks[$record['hook']] = true;
        }

        $missing = [];

        foreach ($results as $result) {
            if (! isset($retainedHooks[$result['hook']])) {
                $missing[] = $result['hook'];
            }
        }

        return $missing === []
            ? ExecutedSubject::passed($name, sprintf(
                'The %s store holds %d monitoring record(s) for this model when read through an '
                    . 'instance that did not write them, and every hook that ran is named among them.',
                $read['store'],
                $read['retained'],
            ))
            : ExecutedSubject::failed($name, sprintf(
                'The retained records do not name every hook that ran; %s produced a result that was '
                    . 'not kept, so the evidence of the monitoring is partial.',
                implode(', ', $missing),
            ));
    }

    /**
     * The records this check wrote are removed again, and the count is checked.
     */
    private static function residueIsRemoved(AiGovernanceDrillInterface $drill, int $written, int $before): ExecutedSubject
    {
        $name = 'this check removes the monitoring records it wrote';

        try {
            $removed = $drill->purgeRetainedMonitoring(AiGovernanceRecordObserver::PROBE_MODEL);
            $after = self::retainedCount($drill);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed($name, sprintf(
                'The monitoring record store refused to dispose of the records this check wrote, so '
                    . 'every compliance run leaves %d more behind: %s.',
                $written,
                $failure->getMessage(),
            ));
        }

        if ($after !== 0) {
            return ExecutedSubject::failed($name, sprintf(
                'Disposal reported removing %d record(s) and %d remain for the reserved probe model, '
                    . 'so this check leaves residue that grows with every compliance report.',
                $removed,
                $after,
            ));
        }

        return ExecutedSubject::passed($name, sprintf(
            'The %d monitoring record(s) this check wrote — and the %d that a previous run had left '
                . 'for the reserved probe model — were disposed of through the store\'s own retention '
                . 'path, so the deployment\'s monitoring history is left exactly as it was found.',
            $written,
            $before,
        ));
    }

    private static function retainedCount(AiGovernanceDrillInterface $drill): int
    {
        try {
            return $drill->readRetainedMonitoringBack(AiGovernanceRecordObserver::PROBE_MODEL)['retained'];
        } catch (Throwable) {
            // A store that cannot be read yields a count of zero rather than a
            // throw here: the failure it represents is measured by the retention
            // subject, which calls the same method and reports what happened. A
            // second report of one failure would make the measurement look like
            // two.
            return 0;
        }
    }

    /**
     * @param list<ExecutedSubject>                                                                       $subjects
     * @param list<array{hook: string, healthy: bool, message: string, metrics: array<string, mixed>}>    $results
     *
     * @return non-empty-string
     */
    private static function describe(array $subjects, array $results): string
    {
        $failed = [];
        $unhealthy = 0;

        foreach ($subjects as $subject) {
            if (! $subject->passed) {
                $failed[] = $subject->name;
            }
        }

        foreach ($results as $result) {
            if (! $result['healthy']) {
                ++$unhealthy;
            }
        }

        // The health of the probe model is reported and deliberately does not
        // decide the fact. What is under measurement is whether monitoring HAPPENS
        // and is retained; whether a particular model passes its checks is a fact
        // about that model, and grading the clause on it would mean a deployment
        // could satisfy Clause 9.1 by having nothing wrong with one synthetic
        // record.
        $health = sprintf(
            ' %d of %d hook result(s) reported the reserved probe model unhealthy, which is '
                . 'reported and does not decide this fact.',
            $unhealthy,
            count($results),
        );

        if ($failed === []) {
            return sprintf(
                'Monitoring runs and its results are kept: %d hook(s) answered against a registered '
                    . 'model, every result was retained where an instance that did not write them '
                    . 'can read it, and the records this check wrote were disposed of again.%s',
                count($results),
                $health,
            );
        }

        return sprintf(
            '%d of %d subjects failed: %s. This deployment cannot show an auditor evidence of its AI '
                . 'monitoring results, which is what ISO 42001 Clause 9.1 asks it to retain.%s',
            count($failed),
            count($subjects),
            implode('; ', $failed),
            $health,
        );
    }
}
