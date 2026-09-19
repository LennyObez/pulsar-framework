<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\PdoConnection;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extension\AiGovernance\AiGovernanceServiceProvider;
use Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\Store\DbAiTransparency;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency;
use Pulsar\Extension\AiGovernance\Tests\Support\AiGovernanceSchema;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Extension\AiGovernance\Transparency\TransparencyExemption;

use function array_map;

/**
 * The Article 50 register survives the worker that declared into it.
 *
 * THIS IS THE OBLIGATION THAT BINDS TODAY, which is why it gets a file of its
 * own rather than three more cases in {@see DurableStoresOutliveTheProcessTest}.
 * Article 50 has applied since 2 August 2026; the digital omnibus deferred
 * Chapter III to 2 December 2027 and 2 August 2028 and left this Article where it
 * was. The extension shipped a durable store for each of the five deferred
 * subsystems and bound the one in force to an in-memory store unconditionally, so
 * the register for the live duty was the only one that did not outlive a restart.
 *
 * EVERY DURABILITY ASSERTION READS THROUGH A SECOND STORE INSTANCE, never through
 * the one that performed the write, exactly as the sibling file does. That is the
 * whole difference between a store that retains and one that remembers.
 *
 * The `theInMemoryStore...` case asserts the opposite, so this file measures the
 * difference rather than describing it. It is not there to shame the in-memory
 * store: that store is the right choice for a deployment declaring every surface
 * in code on every boot, and its own docblock argues so. It is the wrong choice
 * for the shape the contract also permits — a surface declared at runtime — and
 * before this change no deployment could choose otherwise.
 */
#[CoversClass(DbAiTransparency::class)]
#[CoversClass(AiGovernanceServiceProvider::class)]
final class DurableTransparencyOutlivesTheProcessTest extends TestCase
{
    private const int GENERATED_AT = 1_785_628_800;

    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = AiGovernanceSchema::connection();
    }

    // --- Durability -----------------------------------------------------------

    #[Test]
    public function aDeclaredPositionSurvivesTheStoreThatDeclaredIt(): void
    {
        new DbAiTransparency($this->connection)->declare($this->policy());

        $back = $this->freshStore()->policyFor('checkout.assistant');

        self::assertNotNull($back, 'the declaration must be visible to a store that did not write it');
        self::assertSame('checkout.assistant', $back->surfaceId);
        self::assertTrue($back->interactsWithNaturalPersons);
        self::assertTrue($back->owesDisclosure());
        self::assertTrue($back->owesMarking());
    }

    #[Test]
    public function theInMemoryStoreLosesTheDeclarationWithTheInstanceThatHeldIt(): void
    {
        $store = new InMemoryAiTransparency();
        $store->declare($this->policy());

        self::assertNotNull($store->policyFor('checkout.assistant'), 'the writer itself can still see it');
        self::assertNull(
            new InMemoryAiTransparency()->policyFor('checkout.assistant'),
            'and nothing else ever can, which is why it could not be the only option '
                . 'for the one AI Act obligation already in force',
        );
    }

    #[Test]
    public function theNoticeAndItsLanguageSurvive(): void
    {
        new DbAiTransparency($this->connection)->declare($this->policy());

        $disclosure = $this->freshStore()->disclosureFor('checkout.assistant');

        self::assertNotNull($disclosure);

        // The sentence itself, not a flag meaning "we told them somehow": Article
        // 50(5) asks for information given in a clear and distinguishable manner,
        // and a store that round-tripped a boolean would lose the only thing that
        // makes the notice checkable.
        self::assertSame('You are chatting with an AI assistant.', $disclosure->notice);
        self::assertSame('en-GB', $disclosure->locale);
    }

    #[Test]
    public function theGeneratedKindsSurviveAsTheKindsTheyWere(): void
    {
        new DbAiTransparency($this->connection)->declare($this->policy(
            generates: [SyntheticContentKind::Text, SyntheticContentKind::Image],
        ));

        $back = $this->freshStore()->policyFor('checkout.assistant');

        self::assertNotNull($back);
        self::assertSame(
            [SyntheticContentKind::Text, SyntheticContentKind::Image],
            $back->generates,
        );

        // Article 50(4) attaches a further deployer duty to image, audio and video
        // and not to text, so a store that flattened the kinds into "content"
        // would lose the distinction the duty turns on.
        self::assertTrue($back->mayRequireDeepFakeDisclosure());
    }

    #[Test]
    public function aClaimedExemptionSurvivesAsAClaimRatherThanAsAnAbsence(): void
    {
        new DbAiTransparency($this->connection)->declare(new AiTransparencyPolicy(
            surfaceId: 'newsroom.summariser',
            interactsWithNaturalPersons: false,
            generates: [SyntheticContentKind::Text],
            exemption: TransparencyExemption::AssistiveEditingOnly,
        ));

        $back = $this->freshStore()->policyFor('newsroom.summariser');

        self::assertNotNull($back);
        self::assertSame(TransparencyExemption::AssistiveEditingOnly, $back->exemption);

        // The exemption has to come back as the claim it is, because the report
        // carries it as an operator assertion to be justified. A store that lost
        // it would turn a claimed exemption into a duty nobody noticed was owed.
        self::assertFalse($back->owesMarking());
    }

    #[Test]
    public function theCarveBackFlagSurvives(): void
    {
        new DbAiTransparency($this->connection)->declare(new AiTransparencyPolicy(
            surfaceId: 'tipline',
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure('You are interacting with an AI system.', 'en'),
            publicCrimeReporting: true,
        ));

        $back = $this->freshStore()->policyFor('tipline');

        self::assertNotNull($back);
        self::assertTrue(
            $back->publicCrimeReporting,
            'Article 50(1) carves the law-enforcement exemption back out for this case, so losing '
                . 'the flag would let a later declaration claim an exemption the Act refuses',
        );
    }

    #[Test]
    public function redeclaringASurfaceReplacesItsPositionRatherThanAddingOne(): void
    {
        $store = new DbAiTransparency($this->connection);
        $store->declare($this->policy());
        $store->declare($this->policy(generates: [SyntheticContentKind::Audio]));

        $declared = $this->freshStore()->declared();

        self::assertCount(1, $declared, 'a surface holds one position, as the in-memory store does');
        self::assertSame([SyntheticContentKind::Audio], $declared[0]->generates);
    }

    #[Test]
    public function declaredIsOrderedByTheIdentifierRatherThanByWriteOrder(): void
    {
        $store = new DbAiTransparency($this->connection);
        $store->declare($this->policy(surfaceId: 'zeta'));
        $store->declare($this->policy(surfaceId: 'alpha'));

        // Insertion order is not recoverable across a restart without a clock or a
        // sequence this store deliberately does not keep, so the order is a
        // property of the data. The contract specifies a set of declared surfaces,
        // not a sequence.
        self::assertSame(
            ['alpha', 'zeta'],
            array_map(
                static fn(AiTransparencyPolicy $policy): string => $policy->surfaceId,
                $this->freshStore()->declared(),
            ),
        );
    }

    // --- Marking --------------------------------------------------------------

    #[Test]
    public function aMarkIsMintedForASurfaceAnotherInstanceDeclared(): void
    {
        new DbAiTransparency($this->connection)->declare($this->policy());

        $mark = $this->freshStore()->mark(
            'checkout.assistant',
            SyntheticContentKind::Text,
            'acme/gpt',
            self::GENERATED_AT,
        );

        self::assertSame(SyntheticContentKind::Text, $mark->kind);
        self::assertSame('acme/gpt', $mark->modelId);

        // The instant handed in, not one this store read from a clock. Whatever
        // produced the output knows when it did; a marker guessing would be
        // recording the time of marking and calling it the time of generation.
        self::assertSame(self::GENERATED_AT, $mark->generatedAt);
    }

    #[Test]
    public function aMarkIsRefusedForASurfaceNobodyDeclared(): void
    {
        $this->expectException(AiGovernanceException::class);

        (void) $this->freshStore()->mark(
            'never.declared',
            SyntheticContentKind::Text,
            'acme/gpt',
            self::GENERATED_AT,
        );
    }

    #[Test]
    public function markingWritesNothing(): void
    {
        $store = new DbAiTransparency($this->connection);
        $store->declare($this->policy());
        (void) $store->mark('checkout.assistant', SyntheticContentKind::Text, 'acme/gpt', self::GENERATED_AT);

        // A durable store that recorded every mark would be keeping a second
        // register the contract has no method to read back, growing without bound.
        // Whether such a register SHOULD exist is a separate question; this store
        // must not answer it by accident.
        self::assertCount(1, $this->freshStore()->declared());
    }

    // --- A row is not trusted just because a column parsed ---------------------

    #[Test]
    public function aStoredPositionArticle50ForbidsIsRefusedOnTheWayOut(): void
    {
        // Interacts with people, claims no exemption, carries no notice. The
        // constructor refuses this on the way in; a store that assembled the
        // object field by field would hand it back on the way out, and the report
        // would then list a surface in a state the law does not allow.
        $this->insertRow(
            surfaceId: 'hand.written',
            interacts: 1,
            notice: null,
            locale: null,
            generates: '[]',
            exemption: 'none',
        );

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/Article 50\(1\)/');

        (void) $this->freshStore()->policyFor('hand.written');
    }

    #[Test]
    public function aRowHoldingHalfADisclosureIsCorruptRatherThanAbsent(): void
    {
        $this->insertRow(
            surfaceId: 'half.written',
            interacts: 0,
            notice: 'You are chatting with an AI assistant.',
            locale: null,
            generates: '[]',
            exemption: 'none',
        );

        $this->expectException(AiGovernanceException::class);

        (void) $this->freshStore()->policyFor('half.written');
    }

    #[Test]
    public function aContentKindOutsideArticle50IsACorruptRecord(): void
    {
        $this->insertRow(
            surfaceId: 'odd.kind',
            interacts: 0,
            notice: null,
            locale: null,
            generates: '["hologram"]',
            exemption: 'none',
        );

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/generates/');

        (void) $this->freshStore()->policyFor('odd.kind');
    }

    #[Test]
    public function anExemptionTheActDoesNotGrantIsACorruptRecord(): void
    {
        $this->insertRow(
            surfaceId: 'odd.exemption',
            interacts: 0,
            notice: null,
            locale: null,
            generates: '[]',
            exemption: 'because_we_said_so',
        );

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/exemption/');

        (void) $this->freshStore()->policyFor('odd.exemption');
    }

    // --- The wiring an operator actually gets ---------------------------------

    #[Test]
    public function theShippedWiringGivesADurableRegisterByDefault(): void
    {
        self::assertInstanceOf(DbAiTransparency::class, $this->bootTransparency());
    }

    #[Test]
    public function aDeploymentThatDeclaresInCodeCanStillChooseTheInMemoryRegister(): void
    {
        self::assertInstanceOf(
            InMemoryAiTransparency::class,
            $this->bootTransparency(['transparency_store' => 'memory']),
        );
    }

    #[Test]
    public function theRegisterSurvivesARestartOfTheWiringItself(): void
    {
        $connection = $this->connection;

        $this->bootTransparency(connection: $connection)->declare($this->policy());

        // A second container over the same connection: the closest a unit test
        // gets to the next worker. Before this change it read back nothing,
        // whatever the deployment had declared.
        self::assertNotNull(
            $this->bootTransparency(connection: $connection)->policyFor('checkout.assistant'),
        );
    }

    // --- Fixtures -------------------------------------------------------------

    private function freshStore(): DbAiTransparency
    {
        return new DbAiTransparency($this->connection);
    }

    /**
     * @param non-empty-string $surfaceId
     * @param list<SyntheticContentKind> $generates
     */
    private function policy(
        string $surfaceId = 'checkout.assistant',
        array $generates = [SyntheticContentKind::Text],
    ): AiTransparencyPolicy {
        return new AiTransparencyPolicy(
            surfaceId: $surfaceId,
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure('You are chatting with an AI assistant.', 'en-GB'),
            generates: $generates,
        );
    }

    /**
     * A row written past the store, so that hydration is measured rather than
     * assumed to be the inverse of a write this store performed.
     */
    private function insertRow(
        string $surfaceId,
        int $interacts,
        ?string $notice,
        ?string $locale,
        string $generates,
        string $exemption,
    ): void {
        $this->connection->execute(
            'INSERT INTO ai_transparency_policies'
                . ' (surface_id, interacts_with_natural_persons, disclosure_notice, disclosure_locale,'
                . ' generates, exemption, public_crime_reporting)'
                . ' VALUES (:surface_id, :interacts, :notice, :locale, :generates, :exemption, 0)',
            [
                'surface_id' => $surfaceId,
                'interacts' => $interacts,
                'notice' => $notice,
                'locale' => $locale,
                'generates' => $generates,
                'exemption' => $exemption,
            ],
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function bootTransparency(
        array $config = [],
        ?ConnectionInterface $connection = null,
    ): AiTransparencyInterface {
        $container = new Container();
        $container->instance(ConnectionInterface::class, $connection ?? $this->connection);
        $container->instance(
            ExtensionConfigRegistry::class,
            new ExtensionConfigRegistry(sections: ['ai_governance' => $config]),
        );

        new AiGovernanceServiceProvider()->register($container);

        /** @var AiTransparencyInterface $transparency */
        $transparency = $container->get(AiTransparencyInterface::class);

        return $transparency;
    }
}
