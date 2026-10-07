<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Transparency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Transparency\TransparencyExemption;

/**
 * The property under test is that an exemption cannot be spent on the wrong duty.
 *
 * Article 50 grants four escapes and they are not interchangeable. Obviousness
 * excuses telling someone they are talking to a machine; it does not excuse
 * leaving a generated video unmarked, because a viewer's suspicion is not a
 * machine-readable mark. Assistive editing excuses marking; it does not excuse
 * disclosure, since a tool that converses with a person is doing so whether or
 * not it substantially alters their text.
 *
 * A layer that collapsed these into one boolean would let a deployment claim the
 * cheap exemption and be relieved of the expensive duty, which is why this is
 * tested as a matrix over every case and both duties rather than case by case.
 */
#[CoversClass(TransparencyExemption::class)]
final class TransparencyExemptionTest extends TestCase
{
    #[Test]
    public function noExemptionDischargesNothing(): void
    {
        self::assertFalse(TransparencyExemption::None->dischargesDisclosure());
        self::assertFalse(TransparencyExemption::None->dischargesMarking());
    }

    #[Test]
    public function obviousnessExcusesTheNoticeAndNotTheMark(): void
    {
        // Article 50(1) is qualified by obviousness. Article 50(2) is not: the
        // marking duty has no "unless it is obvious" limb anywhere in its text.
        self::assertTrue(TransparencyExemption::ObviousFromContext->dischargesDisclosure());
        self::assertFalse(TransparencyExemption::ObviousFromContext->dischargesMarking());
    }

    #[Test]
    public function assistiveEditingExcusesTheMarkAndNotTheNotice(): void
    {
        // The mirror image, and the one an implementer is likeliest to get wrong:
        // the assistive-editing carve-out sits in 50(2) only.
        self::assertTrue(TransparencyExemption::AssistiveEditingOnly->dischargesMarking());
        self::assertFalse(TransparencyExemption::AssistiveEditingOnly->dischargesDisclosure());
    }

    #[Test]
    public function theLawEnforcementExemptionIsTheOnlyOneReachingBothDuties(): void
    {
        self::assertTrue(TransparencyExemption::LawEnforcementAuthorised->dischargesDisclosure());
        self::assertTrue(TransparencyExemption::LawEnforcementAuthorised->dischargesMarking());

        // And it is the only one, which is the part worth pinning: if a later
        // edit made another case discharge both, this fails.
        $both = [];

        foreach (TransparencyExemption::cases() as $exemption) {
            if ($exemption->dischargesDisclosure() && $exemption->dischargesMarking()) {
                $both[] = $exemption;
            }
        }

        self::assertSame([TransparencyExemption::LawEnforcementAuthorised], $both);
    }

    #[Test]
    public function everyExemptionThatExcusesSomethingNamesWhatMustJustifyIt(): void
    {
        // A claimed exemption with no stated basis is indistinguishable from no
        // claim at all, so the artefact text is part of the contract rather than
        // documentation. Article numbers are required because an assessor reading
        // the report needs to know which limb is being relied on.
        foreach (TransparencyExemption::cases() as $exemption) {
            $artefact = $exemption->justificationArtefact();

            self::assertNotSame('', $artefact);

            if ($exemption === TransparencyExemption::None) {
                continue;
            }

            self::assertStringContainsString(
                'Article 50',
                $artefact,
                $exemption->value . ' must name the paragraph it relies on',
            );
        }
    }

    #[Test]
    public function theNoExemptionCaseSaysNothingIsClaimed(): void
    {
        // It must not read like an excuse, because it is the opposite of one.
        self::assertStringContainsString('no exemption is claimed', TransparencyExemption::None->justificationArtefact());
    }
}
