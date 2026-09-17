<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

/**
 * The seam through which the assessor exercises a deployment's Article 50
 * transparency subsystem.
 *
 * WHY A PORT AND NOT THE CONTRACT ITSELF. {@see AiTransparencyObserver} would
 * rather hold `AiTransparencyInterface` and call it, the way
 * {@see TokenVaultObserver} holds `TokenizationServiceInterface`. It may not:
 * that contract belongs to `pulsar/ai-governance`, which is an OPTIONAL package
 * at trust tier `verified` and kind `product`, is absent from the root autoload
 * by ADR-0004, and is named nowhere in `src/` — {@see ControlEvidenceGatherer}
 * carries its contracts as plain strings and says why. A framework file whose
 * signature named a class from a package the framework does not require would
 * make the dependency arrow point from the core at one of its own extensions.
 *
 * So the arrow is inverted here in the ordinary way: the framework declares what
 * it needs to be able to run, the extension implements it, and the composition
 * root hands the implementation over. It is the same shape as
 * `SpanProcessorInterface`, which the framework declares and the OpenTelemetry
 * extension answers. A deployment with no such extension binds nothing, the
 * observer is handed null, and the fact records that nothing was exercised —
 * ABSENT, which is a different report from false.
 *
 * WHAT AN IMPLEMENTATION MAY AND MAY NOT DO. Everything here returns raw
 * material: the declaration as it was stored, the mark as it will travel. No
 * method returns a judgement, a status or a boolean meaning "this worked",
 * because every comparison that decides the fact is made in
 * {@see AiTransparencyObserver}, inside the component sealed by
 * {@see \Pulsar\Compliance\Control\MeasuringComponent}. An implementation that
 * wanted to flatter its deployment would have to forge the material rather than
 * assert a verdict, and the material is what an assessor would ask to see.
 *
 * The kinds this seam names — `audio`, `image`, `video`, `text` — are
 * Article 50(2)'s own list and not one package's vocabulary. An implementation
 * that does not recognise the kind it is given must throw rather than substitute
 * one: a mark that silently reports a kind nobody asked for is worse evidence
 * than no mark.
 *
 * THIS SEAM WRITES, and it is the second thing in the evidence set that does.
 * {@see declareSurface()} adds a declaration and nothing here withdraws it,
 * because `AiTransparencyInterface` has no withdrawal and inventing one for a
 * compliance check would be adding a method to a shipped contract for the
 * benefit of the thing that inspects it. What bounds the residue is that a
 * declaration is keyed by surface: the observer writes under one reserved id and
 * proves, by declaring it twice, that the store holds one entry for it rather
 * than one per report. See {@see AiTransparencyObserver} for the reserved id and
 * for the subject that establishes the bound.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface AiTransparencyDrillInterface
{
    /**
     * Declare a surface that owes both an Article 50(1) notice and Article 50(2)
     * marking, and record it where the subsystem records the real ones.
     *
     * Not "pretend to declare". The point of the check is that the declaration
     * goes through whatever path a deployment's own surfaces go through, so a
     * store that drops what it is given fails here rather than in production.
     *
     * @param non-empty-string $surfaceId the reserved id the assessor writes under
     * @param non-empty-string $notice    the sentence a person would be shown
     * @param non-empty-string $locale    BCP 47 tag the notice is written in
     * @param non-empty-string $kind      one of `audio`, `image`, `video`, `text`
     *
     * @throws Throwable when the subsystem refuses the declaration; the refusal is
     *         reported as a subject that RAN and failed, never as a check that did
     *         not happen
     */
    public function declareSurface(string $surfaceId, string $notice, string $locale, string $kind): void;

    /**
     * What the subsystem holds for a surface, or null when it holds nothing.
     *
     * Null is a legitimate answer and the observer reads it as one: a subsystem
     * that forgets a declaration the moment it is made is exactly the deployment
     * this check exists to distinguish from a working one.
     *
     * `notice` and `locale` are null when the surface owes no disclosure, which
     * is a coherent state — an exemption discharges Article 50(1) — and not the
     * same as a surface that owes one and holds none, which the policy type
     * refuses to exist in.
     *
     * @param non-empty-string $surfaceId
     *
     * @return array{
     *     surface_id: string,
     *     owes_disclosure: bool,
     *     owes_marking: bool,
     *     notice: string|null,
     *     locale: string|null
     * }|null
     */
    #[NoDiscard]
    public function policyFor(string $surfaceId): ?array;

    /**
     * Mint the Article 50(2) mark for one generated output, in both forms it
     * travels in.
     *
     * `header` is the mark as a transport field, which is where a proxy, a
     * crawler or an auditor's tooling meets it. `machine_readable` is the same
     * assertion as data, for transports that carry structure. Both are returned
     * because Article 50(2) asks for marking that is machine-readable AND
     * detectable, and a deployment that can produce only one of the two forms
     * discharges only part of it.
     *
     * The timestamp is the caller's, never a clock read taken here: whatever
     * produced an output knows when it did, and a marker that stamped its own
     * clock would be recording the time of MARKING and calling it the time of
     * generation. The observer hands a fixed instant in and checks that the same
     * instant comes back, which is how that property is measured rather than
     * assumed.
     *
     * @param non-empty-string $surfaceId
     * @param non-empty-string $kind      one of `audio`, `image`, `video`, `text`
     * @param non-empty-string $modelId   the model credited with the output
     * @param int              $generatedAt Unix timestamp the output was generated at
     *
     * @return array{header: string, machine_readable: array<string, scalar>}
     *
     * @throws Throwable when the surface was never declared. Refusing is correct
     *         and the observer asserts it: a mark that cannot be traced to a
     *         declared policy is an assertion about nothing
     */
    #[NoDiscard]
    public function mark(string $surfaceId, string $kind, string $modelId, int $generatedAt): array;

    /**
     * Every surface id that has declared a position.
     *
     * Read twice by the observer, before and after its own declaration, so that
     * what this check leaves behind is measured rather than promised.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function declaredSurfaces(): array;
}
