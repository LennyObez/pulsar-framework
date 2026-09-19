<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiTransparencyDrill;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\TransparencyExemption;
use ValueError;

/**
 * The compliance seam marshals faithfully, and it decides nothing.
 *
 * WHAT IS UNDER TEST HERE, and what is deliberately not. The framework's assessor
 * cannot call {@see \Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface}
 * — that contract belongs to this optional package and is named nowhere in
 * `src/` — so it reaches the subsystem through a port this class answers. Every
 * judgement about what came back is made in
 * {@see \Pulsar\Compliance\Evidence\AiTransparencyObserver}, inside the sealed
 * measuring component. What is left for this class to get wrong is the
 * translation, and translation is what these tests hold it to: the position that
 * goes in is the position that comes out, the mark carries what it was minted
 * for, and a refusal from the subsystem reaches the caller as a refusal rather
 * than as a benign default.
 *
 * The last of those is worth stating twice. A seam that swallowed an exception
 * and returned an empty array would turn a subsystem that refuses to mark an
 * undeclared surface — which is the correct behaviour — into one that appears to
 * have marked nothing, and the compliance fact would then be decided by this file
 * rather than by the deployment.
 */
#[CoversClass(AiTransparencyDrill::class)]
final class AiTransparencyDrillTest extends TestCase
{
    private const string SURFACE = 'checkout.assistant';

    private const string NOTICE = 'You are interacting with an AI system.';

    private const string MODEL = 'acme/assistant-1';

    /** 2026-08-02T00:00:00Z, the day Article 50 began to apply. */
    private const int GENERATED_AT = 1_785_628_800;

    #[Test]
    public function aDeclaredSurfaceOwesBothArticle50Duties(): void
    {
        $store = new InMemoryAiTransparency();
        $drill = new AiTransparencyDrill($store);

        $drill->declareSurface(self::SURFACE, self::NOTICE, 'en-GB', 'text');

        self::assertSame(
            [
                'surface_id' => self::SURFACE,
                'owes_disclosure' => true,
                'owes_marking' => true,
                'notice' => self::NOTICE,
                'locale' => 'en-GB',
            ],
            $drill->policyFor(self::SURFACE),
        );

        // And the declaration went into the store an application's own surfaces
        // use, not into a copy kept for the report.
        self::assertInstanceOf(AiTransparencyPolicy::class, $store->policyFor(self::SURFACE));
    }

    #[Test]
    public function anUndeclaredSurfaceHoldsNothing(): void
    {
        $drill = new AiTransparencyDrill(new InMemoryAiTransparency());

        self::assertNull($drill->policyFor('never-declared'));
    }

    /**
     * A surface whose exemption discharges Article 50(1) legitimately holds no
     * notice, and that is a different state from a surface that owes one and has
     * none — which the policy type refuses to exist in at all.
     *
     * The seam has to be able to express the first, or an assessor reading a
     * report could not tell a considered exemption from an oversight.
     */
    #[Test]
    public function anExemptSurfaceReportsOwingNothingRatherThanHoldingNothing(): void
    {
        $store = new InMemoryAiTransparency();
        $store->declare(new AiTransparencyPolicy(
            surfaceId: self::SURFACE,
            interactsWithNaturalPersons: true,
            exemption: TransparencyExemption::ObviousFromContext,
        ));

        self::assertSame(
            [
                'surface_id' => self::SURFACE,
                'owes_disclosure' => false,
                'owes_marking' => false,
                'notice' => null,
                'locale' => null,
            ],
            new AiTransparencyDrill($store)->policyFor(self::SURFACE),
        );
    }

    #[Test]
    public function theMarkComesBackInBothFormsCarryingWhatItWasMintedFor(): void
    {
        $drill = new AiTransparencyDrill(new InMemoryAiTransparency());
        $drill->declareSurface(self::SURFACE, self::NOTICE, 'en', 'image');

        $mark = $drill->mark(self::SURFACE, 'image', self::MODEL, self::GENERATED_AT);

        self::assertSame(
            [
                'ai_generated' => true,
                'kind' => 'image',
                'model' => self::MODEL,
                'surface' => self::SURFACE,
                'generated_at' => self::GENERATED_AT,
            ],
            $mark['machine_readable'],
        );
        self::assertStringContainsString('ai-generated=?1', $mark['header']);
        self::assertStringContainsString(self::MODEL, $mark['header']);
        self::assertStringContainsString((string) self::GENERATED_AT, $mark['header']);
    }

    /**
     * The instant is the caller's. A seam that read a clock here would make every
     * mark record the time of MARKING and call it the time of generation, and the
     * observer's check for that property would be comparing this file's clock with
     * itself.
     */
    #[Test]
    public function theGenerationInstantIsTheOneHandedIn(): void
    {
        $drill = new AiTransparencyDrill(new InMemoryAiTransparency());
        $drill->declareSurface(self::SURFACE, self::NOTICE, 'en', 'text');

        $mark = $drill->mark(self::SURFACE, 'text', self::MODEL, self::GENERATED_AT);

        self::assertSame(self::GENERATED_AT, $mark['machine_readable']['generated_at']);
    }

    /**
     * A refusal from the subsystem reaches the caller as a refusal.
     */
    #[Test]
    public function markingASurfaceNobodyDeclaredIsRefused(): void
    {
        $drill = new AiTransparencyDrill(new InMemoryAiTransparency());

        $this->expectException(AiGovernanceException::class);

        (void) $drill->mark('never-declared', 'text', self::MODEL, self::GENERATED_AT);
    }

    /**
     * And a kind outside Article 50(2)'s own list is refused rather than
     * substituted.
     *
     * The Article names synthetic audio, image, video or text content and nothing
     * else. A seam that quietly fell back to `text` would produce a mark reporting
     * a kind nobody asked for — worse evidence than no mark, because it looks
     * corroborated.
     */
    #[Test]
    public function aKindTheArticleDoesNotNameIsRefused(): void
    {
        $drill = new AiTransparencyDrill(new InMemoryAiTransparency());

        $this->expectException(ValueError::class);

        $drill->declareSurface(self::SURFACE, self::NOTICE, 'en', 'hologram');
    }

    #[Test]
    public function everyDeclaredSurfaceIsEnumerated(): void
    {
        $drill = new AiTransparencyDrill(new InMemoryAiTransparency());
        $drill->declareSurface('first', self::NOTICE, 'en', 'text');
        $drill->declareSurface('second', self::NOTICE, 'en', 'audio');

        self::assertSame(['first', 'second'], $drill->declaredSurfaces());
    }

    /**
     * Declaring twice under one id leaves one entry.
     *
     * This is the property the observer rests its residue bound on — it cannot
     * withdraw what it declares, so it proves instead that a second run replaces
     * rather than adds — and the shipped store is where that property lives.
     */
    #[Test]
    public function redeclaringASurfaceReplacesItRatherThanAddingToIt(): void
    {
        $drill = new AiTransparencyDrill(new InMemoryAiTransparency());
        $drill->declareSurface(self::SURFACE, self::NOTICE, 'en', 'text');
        $drill->declareSurface(self::SURFACE, 'A revised notice.', 'fr', 'text');

        $policy = $drill->policyFor(self::SURFACE);

        self::assertSame([self::SURFACE], $drill->declaredSurfaces());
        self::assertNotNull($policy);
        self::assertSame('fr', $policy['locale']);
    }
}
