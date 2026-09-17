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

use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function sort;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * Whether this deployment can actually discharge an Article 50 transparency duty,
 * or only name a class that would.
 *
 * WHY THIS EXISTS. The EU AI Act mapping satisfied nothing at all, on any
 * deployment, and it was right to: both of its probes read a resolved identity,
 * and resolution stopped proving behaviour when
 * {@see \Pulsar\Compliance\Control\ObservationGrade::provesBehaviour()} was
 * narrowed to Measured. The mapping was honest and inert. That is a control stuck
 * in one direction, which is the same class of broken instrument as one stuck in
 * the other — the ADR-0062 residue said so in those words — and Article 50 is the
 * worst place in the catalogue to leave one, because it is the single AI Act duty
 * a framework carries a real share of and it has BOUND SINCE 2 AUGUST 2026. The
 * digital omnibus deferred Chapter III to 2027 and 2028 and left this Article
 * exactly where it was.
 *
 * It was worse than inert. `ai_transparency_resolved` could not be present on any
 * deployment this release can build: {@see \Pulsar\Core\Wiring\ComplianceCatalogWiring}
 * resolved eight of the AI governance contracts and not this one, so the fact read
 * "nothing answered AiTransparencyInterface" against a container that had bound
 * it. The control could reach neither outcome for a reason that was a wiring
 * omission rather than a property of any deployment.
 *
 * WHAT IT RUNS, in order, all of it against the subsystem that serves the
 * deployment:
 *
 *   1. declare a surface owing both Article 50 duties, and read the policy back
 *   2. confirm the declaration is visible where the report would list it
 *   3. mint the Article 50(2) mark for one generated output
 *   4. check the machine-readable form says what was asked of it — including the
 *      generation instant, which must be the one handed in
 *   5. check the mark travels in a field a machine can detect it in
 *   6. confirm a mark is REFUSED for a surface that was never declared
 *   7. confirm this check leaves one declaration behind and not one per run
 *
 * Together they are the limb of Article 50 that lives in what a response
 * contains: a surface can state its position, and generated output can be given a
 * mark that is machine-readable and detectable as artificially generated.
 *
 * READ WHAT THIS STILL DOES NOT SAY, because the split is the point and the
 * mapping keeps it. It does not say a person was shown a notice, and it does not
 * say real output left the process carrying a mark. The first is discharged where
 * the notice is rendered and the second where the response is written, and no
 * container can see either. `ai-act-art-50-1` and `ai-act-art-50-2` remain
 * operator artefacts naming the rendered notice and the captured response. What
 * moved is one control and one only: `ai-act-art-50-capability`, which used to
 * rest on a class name and now rests on the subsystem having done the work.
 *
 * THIS MEASUREMENT WRITES. It is the second in the evidence set that does, after
 * {@see TokenVaultObserver}, and the bound is different because the contract is:
 * a token vault mapping can be removed and `AiTransparencyInterface` has no
 * withdrawal at all. Adding one so that a compliance check could tidy up after
 * itself would be growing a shipped contract for the benefit of the thing that
 * inspects it. What bounds the residue instead is that declarations are keyed by
 * surface: everything written here goes under {@see PROBE_SURFACE}, a reserved id
 * no request routes to, and subject 7 above establishes by execution that a
 * second run replaces that entry rather than adding one. The residue is exactly
 * one declaration, for the life of the store, whatever the store is — and it is
 * reported in the detail line rather than left for a reader to discover.
 *
 * WHAT IT IS ABOUT. {@see \Pulsar\Compliance\Control\ControlSubject::AiSystemGovernance},
 * through {@see ObservationId::AiTransparencyExercised}, which is the estate
 * `ai-act-art-50-capability` declares. The two must agree or the fact cannot carry
 * the control; see {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()}.
 *
 * ABSENT IS NOT FALSE. The `pulsar/ai-governance` extension is trust tier
 * `verified` and kind `product`: it does not load unless an operator enables it,
 * and a deployment without it binds no {@see AiTransparencyDrillInterface}. This
 * observer is then handed null and reports {@see Measurement::couldNotRun()} —
 * nothing was exercised. That is a gap and the report says so, because a
 * deployment that cannot express Article 50 is not early for a future obligation;
 * it has no way to discharge one it already owes. It is deliberately NOT
 * {@see Observation::noSubject()}, which would retire the control instead of
 * failing it — the evaporation ADR-0062 spent itself removing.
 *
 * ONE RESIDUE THIS TOUCHES AND DOES NOT CLOSE. The gap it leaves behind is still
 * spelled `ControlOutcome::Unsatisfied`, though "claimed and not observed" is
 * neither a pass nor a failure. That naming is a decision for the outcome
 * vocabulary and not for one observer, and nothing here should be read as having
 * settled it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiTransparencyObserver
{
    /**
     * The surface this check declares under.
     *
     * Reserved, namespaced to the compliance subsystem, and routed to by nothing.
     * A deployment that declares a surface of this name is declaring a position
     * about a surface that does not exist, which this check would then overwrite
     * — the one collision worth naming, and it costs an application nothing to
     * avoid.
     */
    public const string PROBE_SURFACE = 'compliance.transparency_probe';

    /**
     * A surface id this check NEVER declares, so that refusing to mark it is a
     * property of the subsystem rather than of the order the subjects ran in.
     */
    private const string UNDECLARED_SURFACE = 'compliance.transparency_probe.never_declared';

    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live Article 50 transparency subsystem';

    /**
     * The notice the declared surface carries.
     *
     * Written out in full rather than left as a token, because
     * `AiInteractionDisclosure` refuses to exist without a sentence a person could
     * read — Article 50(5) asks for a notice "in a clear and distinguishable
     * manner" — and a check that fed it a placeholder would be exercising a path
     * no real declaration takes.
     */
    private const string PROBE_NOTICE = 'You are interacting with an AI system. '
        . 'This notice belongs to a compliance probe surface that serves no request.';

    /** The notice's language. Required by the disclosure type; never defaulted. */
    private const string PROBE_LOCALE = 'en';

    /**
     * The output kind the mark is minted for.
     *
     * Article 50(2) names "synthetic audio, image, video or text content", and
     * text is the kind every deployment's responses can carry whatever else it
     * generates. The subsystem is asked for this kind and the mark is checked for
     * it coming back, so a marker that reports a kind nobody asked for fails here.
     */
    private const string PROBE_KIND = 'text';

    /** The model the synthetic output is credited to. Marks without one are refused. */
    private const string PROBE_MODEL = 'compliance.transparency_probe/model';

    /**
     * The generation instant handed in, and the one the mark must carry back.
     *
     * A fixed value — 2026-08-02T00:00:00Z, the day Article 50 began to apply —
     * rather than a clock read, and the choice is the measurement. The transparency
     * contract states that no generation time is taken from a clock, because
     * whatever produced an output knows when it did and a marker guessing at it
     * would record the time of MARKING and call it the time of generation. Handing
     * in an instant that is plainly not now, and requiring it back, is how that
     * property is observed instead of trusted.
     */
    private const int PROBE_GENERATED_AT = 1_785_628_800;

    /**
     * Exercise the transparency subsystem, or report that there was none to exercise.
     *
     * @param AiTransparencyDrillInterface|null $transparency The seam the deployment
     *        bound, resolved by the composition root. Null when no extension answered
     *        it — which is a fact about this deployment, not a pass and not a silence
     */
    #[NoDiscard]
    public function observe(?AiTransparencyDrillInterface $transparency): Observation
    {
        if ($transparency === null) {
            return Observation::measured(
                ObservationId::AiTransparencyExercised,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No AI transparency subsystem is in service, so nothing was exercised: this '
                        . 'deployment has no way to declare an Article 50 position or to mark '
                        . 'generated output, and Article 50 has applied since 2 August 2026.',
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::AiTransparencyExercised,
            self::exercise($transparency),
            self::class,
        );
    }

    /**
     * Run the subjects against the subsystem that serves the deployment.
     *
     * A throw from the first declaration is reported as a subject that RAN and
     * failed, not as a run that could not happen: the subsystem was called and it
     * refused. That distinction is the one a bound-but-unusable implementation
     * falls on, and collapsing it would hide exactly the deployment this class
     * exists to tell apart from a working one.
     */
    private static function exercise(AiTransparencyDrillInterface $transparency): Measurement
    {
        $before = $transparency->declaredSurfaces();

        try {
            $transparency->declareSurface(
                self::PROBE_SURFACE,
                self::PROBE_NOTICE,
                self::PROBE_LOCALE,
                self::PROBE_KIND,
            );
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'a surface can be declared',
                    sprintf(
                        'The transparency subsystem refused a coherent Article 50 declaration: %s. '
                            . 'Nothing in this deployment can state whether a surface talks to '
                            . 'people or what it generates.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf(
                    'The transparency subsystem is bound but not usable: declaring a surface failed '
                        . 'with %s.',
                    $failure->getMessage(),
                ),
            );
        }

        $results = [
            self::declarationReadsBack($transparency),
            self::declarationIsVisible($transparency),
        ];

        foreach (self::markSubjects($transparency) as $result) {
            $results[] = $result;
        }

        $results[] = self::undeclaredSurfaceIsRefused($transparency);
        $results[] = self::residueIsBounded($transparency, $before);

        return Measurement::completed(self::SUBJECT, $results, self::describe($results));
    }

    /**
     * The declaration must survive being made.
     *
     * Read back through the subsystem rather than remembered here: what is under
     * test is whether the deployment holds the position it was given, and an
     * in-process copy of what was sent would prove only that this method can keep
     * a variable. A store that accepts a declaration and forgets it fails here,
     * and it is the failure that separates a subsystem that works from one that
     * merely resolved.
     */
    private static function declarationReadsBack(AiTransparencyDrillInterface $transparency): ExecutedSubject
    {
        try {
            $policy = $transparency->policyFor(self::PROBE_SURFACE);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the declaration reads back',
                sprintf(
                    'The subsystem refused to return the position it had just been given: %s.',
                    $failure->getMessage(),
                ),
            );
        }

        if ($policy === null) {
            return ExecutedSubject::failed(
                'the declaration reads back',
                'The subsystem holds nothing for a surface it had just been given, so the '
                    . 'declaration did not survive the call that made it.',
            );
        }

        $wrong = [];

        if ($policy['surface_id'] !== self::PROBE_SURFACE) {
            $wrong[] = sprintf(
                'it came back under surface "%s" rather than "%s"',
                $policy['surface_id'],
                self::PROBE_SURFACE,
            );
        }

        // Both duties were declared live, so both must read live. A subsystem that
        // silently discharged one of them would report a surface as owing nothing
        // while the Article still asks it of the deployment.
        if (!$policy['owes_disclosure']) {
            return self::divergence($wrong, 'it reports owing no Article 50(1) notice');
        }

        if (!$policy['owes_marking']) {
            return self::divergence($wrong, 'it reports owing no Article 50(2) marking');
        }

        if ($policy['notice'] !== self::PROBE_NOTICE) {
            $wrong[] = 'the notice it holds is not the one that was declared';
        }

        if ($policy['locale'] !== self::PROBE_LOCALE) {
            $wrong[] = sprintf(
                'the notice language reads "%s" rather than "%s"',
                $policy['locale'] ?? 'none',
                self::PROBE_LOCALE,
            );
        }

        return $wrong === []
            ? ExecutedSubject::passed(
                'the declaration reads back',
                sprintf(
                    'A surface owing both Article 50 duties was declared and read back intact: it '
                        . 'owes a notice, it owes marking, and the notice came back in %s.',
                    self::PROBE_LOCALE,
                ),
            )
            : ExecutedSubject::failed(
                'the declaration reads back',
                'The position that came back is not the one that was declared — ' . implode('; ', $wrong) . '.',
            );
    }

    /**
     * The declaration must be visible where the report would list it.
     *
     * Separate from reading it back by id, and not redundant with it: a subsystem
     * can answer a direct lookup and still enumerate nothing, and the enumeration
     * is what an assessor asking "which surfaces have declared a position" is
     * shown.
     */
    private static function declarationIsVisible(AiTransparencyDrillInterface $transparency): ExecutedSubject
    {
        try {
            $declared = $transparency->declaredSurfaces();
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the declaration is visible to the report',
                sprintf('The subsystem refused to enumerate its declared surfaces: %s.', $failure->getMessage()),
            );
        }

        return in_array(self::PROBE_SURFACE, $declared, true)
            ? ExecutedSubject::passed(
                'the declaration is visible to the report',
                sprintf(
                    'The declared surface appears among the %d this deployment enumerates, so what '
                        . 'was declared is what an assessor would be shown.',
                    count($declared),
                ),
            )
            : ExecutedSubject::failed(
                'the declaration is visible to the report',
                sprintf(
                    'The surface answers a direct lookup and is absent from the %d surface(s) the '
                        . 'subsystem enumerates, so a report of declared positions would not list it.',
                    count($declared),
                ),
            );
    }

    /**
     * Mint the mark, then judge each of its two forms.
     *
     * One subject when minting throws and three when it does not: the two form
     * checks have nothing to judge without a mark, and reporting them as failures
     * would say the forms were wrong when they were never produced.
     *
     * @return list<ExecutedSubject>
     */
    private static function markSubjects(AiTransparencyDrillInterface $transparency): array
    {
        try {
            $mark = $transparency->mark(
                self::PROBE_SURFACE,
                self::PROBE_KIND,
                self::PROBE_MODEL,
                self::PROBE_GENERATED_AT,
            );
        } catch (Throwable $failure) {
            return [ExecutedSubject::failed(
                'a mark is minted for the declared surface',
                sprintf(
                    'The subsystem refused to mark %s output for a surface it holds a declaration '
                        . 'for: %s. Nothing this deployment generates can be marked.',
                    self::PROBE_KIND,
                    $failure->getMessage(),
                ),
            )];
        }

        return [
            ExecutedSubject::passed(
                'a mark is minted for the declared surface',
                sprintf('The subsystem minted an Article 50(2) mark for %s output.', self::PROBE_KIND),
            ),
            self::markIsMachineReadable($mark['machine_readable']),
            self::markTravelsInAStructuredField($mark['header']),
        ];
    }

    /**
     * The machine-readable form must assert what it was asked to assert.
     *
     * Article 50(2) wants marking that is machine-readable AND detectable as
     * artificially generated. The detection point is the assertion itself; the
     * kind, the model and the instant are what make it corroborable, and each is
     * compared against what was handed in rather than merely required to be
     * present. The instant is the one that catches the defect worth catching: a
     * marker that stamps its own clock returns something close to now, not the
     * 2026-08-02 handed to it.
     *
     * @param array<string, scalar> $data
     */
    private static function markIsMachineReadable(array $data): ExecutedSubject
    {
        $wrong = [];

        if (($data['ai_generated'] ?? null) !== true) {
            $wrong[] = 'it does not assert that the content was artificially generated, which is the '
                . 'whole of what Article 50(2) asks a machine to be able to detect';
        }

        if (($data['kind'] ?? null) !== self::PROBE_KIND) {
            $wrong[] = sprintf('it names a content kind other than the "%s" it was given', self::PROBE_KIND);
        }

        if (($data['model'] ?? null) !== self::PROBE_MODEL) {
            $wrong[] = 'it does not carry the model the output was credited to, so the assertion '
                . 'cannot be corroborated against a model registry';
        }

        if (($data['surface'] ?? null) !== self::PROBE_SURFACE) {
            $wrong[] = 'it does not carry the surface whose declared policy demanded it, so the mark '
                . 'cannot be traced back to a declaration';
        }

        if (($data['generated_at'] ?? null) !== self::PROBE_GENERATED_AT) {
            $wrong[] = 'it reports a generation time other than the one it was given, so what it '
                . 'records is when the mark was made rather than when the output was';
        }

        return $wrong === []
            ? ExecutedSubject::passed(
                'the mark is machine-readable',
                'The mark asserts artificial generation as data, and carries back the kind, the '
                    . 'model, the surface and the generation instant it was given.',
            )
            : ExecutedSubject::failed(
                'the mark is machine-readable',
                'The machine-readable form of the mark is not what was asked for — '
                    . implode('; ', $wrong) . '.',
            );
    }

    /**
     * And it must travel in a field a machine meets without knowing anything about
     * this framework.
     *
     * The data form is no use to a proxy or a crawler that never parses a body.
     * What is checked is the same four claims, in the field that carries them: a
     * detection point, and the corroboration behind it.
     */
    private static function markTravelsInAStructuredField(string $header): ExecutedSubject
    {
        $missing = [];

        if (!str_contains($header, 'ai-generated=?1')) {
            $missing[] = 'the ai-generated assertion a parser detects on';
        }

        if (!str_contains($header, self::PROBE_KIND)) {
            $missing[] = 'the content kind';
        }

        if (!str_contains($header, self::PROBE_MODEL)) {
            $missing[] = 'the model';
        }

        if (!str_contains($header, self::PROBE_SURFACE)) {
            $missing[] = 'the surface';
        }

        if (!str_contains($header, (string) self::PROBE_GENERATED_AT)) {
            $missing[] = 'the generation instant';
        }

        return $missing === []
            ? ExecutedSubject::passed(
                'the mark travels in a structured field',
                sprintf(
                    'The mark renders to a %d-byte transport field carrying the ai-generated '
                        . 'assertion and the kind, model, surface and instant behind it.',
                    strlen($header),
                ),
            )
            : ExecutedSubject::failed(
                'the mark travels in a structured field',
                sprintf(
                    'The transport form of the mark omits %s, so a proxy or a crawler reading the '
                        . 'response would not detect it: "%s".',
                    implode(', ', $missing),
                    $header,
                ),
            );
    }

    /**
     * A mark for a surface nobody declared must be refused.
     *
     * The traceability is what makes a mark evidence. A subsystem that mints one
     * for any string it is handed produces assertions that resolve to no declared
     * policy, no risk classification and no accountable surface — which is an
     * assertion about nothing, dressed as compliance with Article 50(2).
     *
     * Asserted by CALLING it, which is why the refusal is a measurement here and
     * not a claim in a docblock.
     */
    private static function undeclaredSurfaceIsRefused(AiTransparencyDrillInterface $transparency): ExecutedSubject
    {
        try {
            $minted = $transparency->mark(
                self::UNDECLARED_SURFACE,
                self::PROBE_KIND,
                self::PROBE_MODEL,
                self::PROBE_GENERATED_AT,
            );
        } catch (Throwable) {
            return ExecutedSubject::passed(
                'an undeclared surface is refused a mark',
                'The subsystem refused to mark output for a surface it holds no declaration for, so '
                    . 'every mark it mints traces back to a declared position.',
            );
        }

        // Reproduced rather than merely reported: an operator told a mark was
        // issued for nothing will want to see the assertion that went out, and it
        // is the shortest route to whatever minted it.
        return ExecutedSubject::failed(
            'an undeclared surface is refused a mark',
            sprintf(
                'The subsystem minted a mark for "%s", which was never declared: %s. A mark that '
                    . 'traces to no policy asserts nothing an assessor can corroborate.',
                self::UNDECLARED_SURFACE,
                $minted['header'],
            ),
        );
    }

    /**
     * What this check leaves behind, measured rather than promised.
     *
     * `AiTransparencyInterface` has no withdrawal, so the declaration made above
     * cannot be removed the way {@see TokenVaultObserver} removes its row. The
     * bound is structural instead: declarations are keyed by surface, so declaring
     * the reserved id a SECOND time must leave the store holding exactly what it
     * held before plus that one id. A store that appended instead would accumulate
     * one entry per compliance report, and this is where that shows — on the second
     * declaration, in the same run, rather than after a year of them.
     *
     * The other half of the assertion is that nothing else moved: every surface the
     * deployment had declared before this check ran must still be declared after
     * it. A compliance probe that disturbed the positions it reports on would be a
     * worse artefact than no report.
     *
     * @param list<string> $before
     */
    private static function residueIsBounded(
        AiTransparencyDrillInterface $transparency,
        array $before,
    ): ExecutedSubject {
        try {
            $transparency->declareSurface(
                self::PROBE_SURFACE,
                self::PROBE_NOTICE,
                self::PROBE_LOCALE,
                self::PROBE_KIND,
            );
            $after = $transparency->declaredSurfaces();
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'this check leaves one declaration and no more',
                sprintf(
                    'Re-declaring the reserved surface "%s" failed with %s, so what this check '
                        . 'leaves in the subsystem could not be established.',
                    self::PROBE_SURFACE,
                    $failure->getMessage(),
                ),
            );
        }

        $expected = array_values(array_unique([...$before, self::PROBE_SURFACE]));
        sort($expected);
        sort($after);

        return $expected === $after
            ? ExecutedSubject::passed(
                'this check leaves one declaration and no more',
                sprintf(
                    'Two declarations of "%s" left the subsystem holding the %d surface(s) it held '
                        . 'before, plus that one reserved id.',
                    self::PROBE_SURFACE,
                    count($before),
                ),
            )
            : ExecutedSubject::failed(
                'this check leaves one declaration and no more',
                sprintf(
                    'The subsystem held %d surface(s) before this check and %d after two '
                        . 'declarations of one reserved id, so it either accumulates a declaration '
                        . 'per report or lost a position it had been given.',
                    count($before),
                    count($after),
                ),
            );
    }

    /**
     * One divergence that ends the comparison, folded in with whatever preceded it.
     *
     * A surface reported as owing neither duty is not one more wrong field: the
     * notice and the language are then legitimately absent, and going on to
     * complain that they do not match would bury the finding that matters under
     * two that follow from it.
     *
     * @param list<string> $wrong
     */
    private static function divergence(array $wrong, string $decisive): ExecutedSubject
    {
        $wrong[] = $decisive;

        return ExecutedSubject::failed(
            'the declaration reads back',
            'The position that came back is not the one that was declared — ' . implode('; ', $wrong) . '.',
        );
    }

    /**
     * The sentence the report prints, naming what failed when something did.
     *
     * The residue is stated on the passing branch as well as the failing one. A
     * measurement that writes has to say what it wrote wherever an assessor will
     * read it, and a note that only appears when something goes wrong is a note
     * nobody reads.
     *
     * @param list<ExecutedSubject> $results
     *
     * @return non-empty-string
     */
    private static function describe(array $results): string
    {
        $failed = [];

        foreach ($results as $result) {
            if (!$result->passed) {
                $failed[] = $result->name . ': ' . $result->detail;
            }
        }

        return $failed === []
            ? sprintf(
                'A surface was declared through the live transparency subsystem, read back with '
                    . 'both Article 50 duties intact, and given a machine-readable mark carrying '
                    . 'the kind, model, surface and generation instant it was minted for; a mark '
                    . 'for an undeclared surface was refused. %d subject(s) ran. One declaration '
                    . 'remains under the reserved surface "%s", which a further run replaces '
                    . 'rather than adds to.',
                count($results),
                self::PROBE_SURFACE,
            )
            : 'The Article 50 transparency subsystem did not discharge what it was asked — '
                . implode(' | ', $failed);
    }
}
