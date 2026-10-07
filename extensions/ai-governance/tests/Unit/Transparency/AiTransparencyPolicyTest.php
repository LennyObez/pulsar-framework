<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Transparency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Extension\AiGovernance\Transparency\TransparencyExemption;

/**
 * The policy exists to make one state unrepresentable.
 *
 * "This surface talks to people, claims no exemption, and carries no notice" is
 * not a configuration Article 50(1) permits, so it is not a value this type can
 * hold. Every test below is about a refusal or about what survives one; there is
 * deliberately no test asserting that a well-formed policy stores its arguments,
 * because a constructor that assigns properties is not the thing at risk here.
 */
#[CoversClass(AiTransparencyPolicy::class)]
final class AiTransparencyPolicyTest extends TestCase
{
    #[Test]
    public function aSurfaceThatTalksToPeopleWithoutANoticeIsRefused(): void
    {
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/Article 50\(1\) requires a disclosure notice/');

        new AiTransparencyPolicy(surfaceId: 'support-chat', interactsWithNaturalPersons: true);
    }

    #[Test]
    public function aSurfaceThatTalksToPeopleWithANoticeIsAccepted(): void
    {
        $policy = new AiTransparencyPolicy(
            surfaceId: 'support-chat',
            interactsWithNaturalPersons: true,
            disclosure: $this->notice(),
        );

        self::assertTrue($policy->owesDisclosure());
        self::assertInstanceOf(AiInteractionDisclosure::class, $policy->disclosure);
    }

    #[Test]
    public function anExemptionThatReachesArticle50Point1RemovesTheNeedForANotice(): void
    {
        $policy = new AiTransparencyPolicy(
            surfaceId: 'obvious-bot',
            interactsWithNaturalPersons: true,
            exemption: TransparencyExemption::ObviousFromContext,
        );

        self::assertFalse($policy->owesDisclosure());
    }

    #[Test]
    public function anExemptionThatDoesNotReachArticle50Point1DoesNotRemoveIt(): void
    {
        // The confusion this guards: assistive editing is a MARKING carve-out. A
        // surface claiming it while conversing with people still owes a notice,
        // and must be refused for want of one rather than quietly excused.
        $this->expectException(AiGovernanceException::class);

        new AiTransparencyPolicy(
            surfaceId: 'writing-assistant',
            interactsWithNaturalPersons: true,
            exemption: TransparencyExemption::AssistiveEditingOnly,
        );
    }

    #[Test]
    public function theLawEnforcementExemptionIsRefusedOnAPublicCrimeReportingSurface(): void
    {
        // Article 50(1) grants the law-enforcement exemption and then takes it
        // back, in terms, for systems "available for the public to report a
        // criminal offence". Claiming both at once is not a policy the Article
        // allows, so it is not one this type will hold.
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/excludes exactly that case from the exemption/');

        new AiTransparencyPolicy(
            surfaceId: 'tip-line',
            interactsWithNaturalPersons: true,
            exemption: TransparencyExemption::LawEnforcementAuthorised,
            publicCrimeReporting: true,
        );
    }

    #[Test]
    public function theLawEnforcementExemptionStandsWhereTheCarveBackDoesNotApply(): void
    {
        $policy = new AiTransparencyPolicy(
            surfaceId: 'investigation-tool',
            interactsWithNaturalPersons: true,
            exemption: TransparencyExemption::LawEnforcementAuthorised,
            publicCrimeReporting: false,
        );

        self::assertFalse($policy->owesDisclosure());
    }

    #[Test]
    public function aGeneratingSurfaceOwesMarkingForWhatItActuallyGenerates(): void
    {
        $policy = new AiTransparencyPolicy(
            surfaceId: 'article-writer',
            interactsWithNaturalPersons: false,
            generates: [SyntheticContentKind::Text],
        );

        self::assertTrue($policy->owesMarking());
        self::assertTrue($policy->owesMarkingFor(SyntheticContentKind::Text));

        // And not for a kind it never emits: the duty attaches to output that
        // exists, not to every kind the enum can name.
        self::assertFalse($policy->owesMarkingFor(SyntheticContentKind::Video));
    }

    #[Test]
    public function anExemptionReachingArticle50Point2RemovesTheMarkingDuty(): void
    {
        $policy = new AiTransparencyPolicy(
            surfaceId: 'spell-checker',
            interactsWithNaturalPersons: false,
            generates: [SyntheticContentKind::Text],
            exemption: TransparencyExemption::AssistiveEditingOnly,
        );

        self::assertFalse($policy->owesMarking());
        self::assertFalse($policy->owesMarkingFor(SyntheticContentKind::Text));
    }

    #[Test]
    public function obviousnessDoesNotRemoveTheMarkingDuty(): void
    {
        // The asymmetry, from the policy's side: a surface may be plainly an AI
        // and still owe a mark on every image it produces.
        $policy = new AiTransparencyPolicy(
            surfaceId: 'obvious-image-bot',
            interactsWithNaturalPersons: true,
            generates: [SyntheticContentKind::Image],
            exemption: TransparencyExemption::ObviousFromContext,
        );

        self::assertFalse($policy->owesDisclosure());
        self::assertTrue($policy->owesMarking());
    }

    #[Test]
    public function aSurfaceWithoutAnIdentityIsRefused(): void
    {
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/must be identified/');

        new AiTransparencyPolicy(surfaceId: '   ', interactsWithNaturalPersons: false);
    }

    #[Test]
    public function anAnonymousSurfaceIsRefusedForItsIdentityBeforeAnythingElse(): void
    {
        // Two things are wrong here: no identity, and no notice on a surface that
        // owes one. The identity must be the one reported, because every other
        // refusal names the surface in its message and a message naming an empty
        // one cannot tell the reader which declaration to go and fix.
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/must be identified/');

        new AiTransparencyPolicy(surfaceId: '', interactsWithNaturalPersons: true);
    }

    #[Test]
    public function theDeployerDeepFakeDutyIsFlaggedForMediaAndNotForText(): void
    {
        $video = new AiTransparencyPolicy(
            surfaceId: 'video-gen',
            interactsWithNaturalPersons: false,
            generates: [SyntheticContentKind::Video],
        );

        $text = new AiTransparencyPolicy(
            surfaceId: 'text-gen',
            interactsWithNaturalPersons: false,
            generates: [SyntheticContentKind::Text],
        );

        // Article 50(4) first subparagraph reaches image, audio and video. Text is
        // governed by the second subparagraph on different terms, so it is not
        // flagged here — which is not the same as being exempt from it.
        self::assertTrue($video->mayRequireDeepFakeDisclosure());
        self::assertFalse($text->mayRequireDeepFakeDisclosure());
    }

    #[Test]
    public function aSurfaceThatNeitherTalksNorGeneratesOwesNothing(): void
    {
        $policy = new AiTransparencyPolicy(surfaceId: 'batch-classifier', interactsWithNaturalPersons: false);

        self::assertFalse($policy->owesDisclosure());
        self::assertFalse($policy->owesMarking());
        self::assertFalse($policy->mayRequireDeepFakeDisclosure());
    }

    private function notice(): AiInteractionDisclosure
    {
        return new AiInteractionDisclosure(
            notice: 'You are chatting with an AI assistant.',
            locale: 'en-GB',
        );
    }
}
