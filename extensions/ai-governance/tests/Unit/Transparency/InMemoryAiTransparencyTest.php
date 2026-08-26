<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Transparency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Extension\AiGovernance\Transparency\TransparencyExemption;

use function array_map;

/**
 * The store's job is to answer two different questions without conflating them.
 *
 * "What must this surface render?" is a rendering question, and a surface that
 * owes nothing answers it with null. "What did this deployment declare?" is a
 * compliance question, and there a surface that owes nothing is a positive fact
 * an assessor needs. Collapsing the two would make an exempt surface and an
 * undeclared one indistinguishable in a report, which is the difference between
 * a considered decision and an oversight.
 */
#[CoversClass(InMemoryAiTransparency::class)]
final class InMemoryAiTransparencyTest extends TestCase
{
    #[Test]
    public function aDeclaredSurfaceThatOwesANoticeReturnsIt(): void
    {
        $store = new InMemoryAiTransparency();
        $store->declare(new AiTransparencyPolicy(
            surfaceId: 'support-chat',
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure('You are chatting with an AI assistant.', 'en-GB'),
        ));

        $disclosure = $store->disclosureFor('support-chat');

        self::assertInstanceOf(AiInteractionDisclosure::class, $disclosure);
        self::assertSame('You are chatting with an AI assistant.', $disclosure->notice);
    }

    #[Test]
    public function anExemptSurfaceRendersNothingButRemainsDeclared(): void
    {
        // The distinction the class exists for. Nothing is rendered, and the
        // decision is still on the record with the exemption that drove it.
        $store = new InMemoryAiTransparency();
        $store->declare(new AiTransparencyPolicy(
            surfaceId: 'obvious-bot',
            interactsWithNaturalPersons: true,
            exemption: TransparencyExemption::ObviousFromContext,
        ));

        self::assertNull($store->disclosureFor('obvious-bot'));

        $declared = $store->declared();

        self::assertCount(1, $declared);
        self::assertSame(TransparencyExemption::ObviousFromContext, $declared[0]->exemption);
    }

    #[Test]
    public function anUndeclaredSurfaceIsAbsentRatherThanExempt(): void
    {
        $store = new InMemoryAiTransparency();

        self::assertNull($store->policyFor('never-declared'));
        self::assertSame([], $store->declared());
    }

    #[Test]
    public function markingAnUndeclaredSurfaceIsRefused(): void
    {
        // A mark that traces to no declared position is an assertion about
        // nothing, and the traceability is the part that makes it evidence.
        $store = new InMemoryAiTransparency();

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/no policy was declared for it/');

        // Cast because mark() is #[NoDiscard]: here the throw IS the result.
        (void) $store->mark('ghost-surface', SyntheticContentKind::Image, 'acme-diffusion-1', 1_770_000_000);
    }

    #[Test]
    public function markingIsProducedEvenWhereAnExemptionWouldExcuseIt(): void
    {
        // An exemption relieves a deployment of being REQUIRED to mark. It never
        // forbids marking, and a caller asking for a mark has already decided
        // this output is generated. Refusing here would turn a carve-out into a
        // prohibition the Article does not contain.
        $store = new InMemoryAiTransparency();
        $store->declare(new AiTransparencyPolicy(
            surfaceId: 'spell-checker',
            interactsWithNaturalPersons: false,
            generates: [SyntheticContentKind::Text],
            exemption: TransparencyExemption::AssistiveEditingOnly,
        ));

        $mark = $store->mark('spell-checker', SyntheticContentKind::Text, 'acme-llm-2', 1_770_000_000);

        self::assertSame('spell-checker', $mark->surfaceId);
        self::assertStringStartsWith('ai-generated=?1', $mark->toHeaderValue());
    }

    #[Test]
    public function redeclaringASurfaceReplacesItRatherThanAccumulating(): void
    {
        // A surface has one position at a time. Keeping both would leave a report
        // reading two contradictory declarations with no rule for choosing.
        $store = new InMemoryAiTransparency();

        $store->declare(new AiTransparencyPolicy(
            surfaceId: 'chat',
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure('You are chatting with an AI.', 'en-GB'),
        ));

        $store->declare(new AiTransparencyPolicy(
            surfaceId: 'chat',
            interactsWithNaturalPersons: true,
            exemption: TransparencyExemption::ObviousFromContext,
        ));

        self::assertCount(1, $store->declared());
        self::assertNull($store->disclosureFor('chat'));
    }

    #[Test]
    public function everyDeclaredSurfaceReachesTheReport(): void
    {
        $store = new InMemoryAiTransparency();

        $store->declare(new AiTransparencyPolicy(
            surfaceId: 'chat',
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure('You are chatting with an AI.', 'en-GB'),
        ));
        $store->declare(new AiTransparencyPolicy(
            surfaceId: 'image-gen',
            interactsWithNaturalPersons: false,
            generates: [SyntheticContentKind::Image],
        ));

        $ids = array_map(static fn(AiTransparencyPolicy $p): string => $p->surfaceId, $store->declared());

        self::assertSame(['chat', 'image-gen'], $ids);
    }
}
