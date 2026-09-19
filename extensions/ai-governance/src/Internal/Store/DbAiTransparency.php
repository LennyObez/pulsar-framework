<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentMark;
use Pulsar\Extension\AiGovernance\Transparency\TransparencyExemption;

use function is_string;

/**
 * Declared Article 50 positions, held where they survive the worker that
 * declared them.
 *
 * ARTICLE 50 IS THE OBLIGATION THAT BINDS TODAY. It has applied since 2 August
 * 2026, and the digital omnibus that pushed Chapter III to 2 December 2027 and
 * 2 August 2028 left it exactly where it was. Every other subsystem in this
 * extension backs a deferred duty and every one of them got a durable store; this
 * one did not, so the register for the duty in force was the only register that
 * did not outlive a restart.
 *
 * WHEN TO PREFER {@see InMemoryAiTransparency}, because it is not a placeholder
 * and its argument is real. A deployment that declares every surface in code, on
 * every boot, rebuilds its register from the source that defines it, and a
 * database row is then a second copy that can fall out of step with the code it
 * describes — a stale row saying a surface owes no disclosure is worse than no
 * row. Such a deployment sets `transparency_store` to `memory` and the compliance
 * report repeats the choice back. What the in-memory store cannot serve is the
 * other shape the contract permits: `declare()` is public and `#[Api]` and says
 * nothing about only being callable at boot, so a deployment whose surfaces are
 * declared by an operator at runtime loses them at the next restart, silently,
 * with the report then listing fewer surfaces than were declared.
 *
 * MARKING STAYS A PURE FUNCTION OF ITS ARGUMENTS, and that is the one place this
 * store deliberately does NOT differ from the in-memory one. {@see mark()} reads
 * the policy to confirm the surface was declared and writes nothing: the mark is
 * derived from what the caller handed in, and a store that also recorded every
 * mark would be keeping a second register the contract has no method to read
 * back, growing without bound, for evidence `ai-act-art-50-2` asks the operator
 * to produce from the captured response instead. A register of minted marks is a
 * real and separate gap; it is not closed by making this method write.
 *
 * READ ORDER IS BY SURFACE ID, not by declaration order. {@see InMemoryAiTransparency}
 * returns its array in insertion order because that is what a PHP array does, and
 * the contract specifies a set of declared surfaces rather than a sequence. A
 * durable store cannot know insertion order across a restart without inventing a
 * clock or a sequence, and both would be facts about the store's history that
 * nothing measured. Sorting on the identifier is the one order that is a property
 * of the data.
 *
 * @internal
 */
#[Internal(reason: 'Durable store behind AiTransparencyInterface; the contract is the public surface')]
final readonly class DbAiTransparency implements AiTransparencyInterface
{
    private const string TABLE = 'ai_transparency_policies';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function declare(AiTransparencyPolicy $policy): void
    {
        $insert = 'INSERT INTO ' . self::TABLE
            . ' (surface_id, interacts_with_natural_persons, disclosure_notice, disclosure_locale,'
            . ' generates, exemption, public_crime_reporting)'
            . ' VALUES (:surface_id, :interacts_with_natural_persons, :disclosure_notice,'
            . ' :disclosure_locale, :generates, :exemption, :public_crime_reporting)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['surface_id'],
                [
                    'interacts_with_natural_persons',
                    'disclosure_notice',
                    'disclosure_locale',
                    'generates',
                    'exemption',
                    'public_crime_reporting',
                ],
            ),
            [
                'surface_id' => $policy->surfaceId,
                'interacts_with_natural_persons' => $policy->interactsWithNaturalPersons ? 1 : 0,
                'disclosure_notice' => $policy->disclosure?->notice,
                'disclosure_locale' => $policy->disclosure?->locale,
                'generates' => StoredValue::encode(self::kindsToArray($policy->generates)),
                'exemption' => $policy->exemption->value,
                'public_crime_reporting' => $policy->publicCrimeReporting ? 1 : 0,
            ],
        );
    }

    #[Override]
    #[NoDiscard]
    public function policyFor(string $surfaceId): ?AiTransparencyPolicy
    {
        $row = $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE surface_id = :surface_id',
            ['surface_id' => $surfaceId],
        )->first();

        return $row === null ? null : $this->hydrate($row);
    }

    #[Override]
    #[NoDiscard]
    public function disclosureFor(string $surfaceId): ?AiInteractionDisclosure
    {
        $policy = $this->policyFor($surfaceId);

        if (! $policy instanceof AiTransparencyPolicy || ! $policy->owesDisclosure()) {
            return null;
        }

        // A policy that owes a disclosure holds one: its constructor refused to
        // exist otherwise, and the hydrator builds it through that constructor.
        return $policy->disclosure;
    }

    #[Override]
    #[NoDiscard]
    public function mark(
        string $surfaceId,
        SyntheticContentKind $kind,
        string $modelId,
        int $generatedAt,
    ): SyntheticContentMark {
        // Existence is asked of the table rather than of a hydrated policy: the
        // question is whether the surface was declared, and hydrating the whole
        // row to answer it would make an unrelated corrupt column refuse a mark
        // for a surface that is on record.
        $row = $this->connection->query(
            'SELECT surface_id FROM ' . self::TABLE . ' WHERE surface_id = :surface_id',
            ['surface_id' => $surfaceId],
        )->first();

        if ($row === null) {
            throw AiGovernanceException::surfaceNotDeclared($surfaceId);
        }

        // Marking is NOT conditional on owesMarkingFor(), matching the in-memory
        // store. An exemption excuses a deployment from being REQUIRED to mark; it
        // never forbids marking, and a caller asking for a mark has decided this
        // output is generated. The exemption is carried into the report instead.
        return new SyntheticContentMark($kind, $surfaceId, $modelId, $generatedAt);
    }

    /**
     * @return list<AiTransparencyPolicy>
     */
    #[Override]
    #[NoDiscard]
    public function declared(): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY surface_id ASC',
        )->map(fn(Row $row): AiTransparencyPolicy => $this->hydrate($row));
    }

    /**
     * Rebuild the declaration through its own constructor.
     *
     * Going through the constructor rather than assembling the object field by
     * field is what keeps a stored row from expressing a position Article 50 does
     * not allow. A row saying a surface talks to people, claims no exemption and
     * carries no notice is refused on the way out exactly as it would have been
     * refused on the way in — a table any operator can write to is not a source
     * of coherent declarations just because a coherent one was written first.
     */
    private function hydrate(Row $row): AiTransparencyPolicy
    {
        $notice = StoredValue::optional($row->getNullableString('disclosure_notice'));
        $locale = StoredValue::optional($row->getNullableString('disclosure_locale'));

        // Half a disclosure is corruption rather than absence: the type requires
        // both, so a row holding one of them cannot say what the deployment
        // declared and must not be read as saying it declared nothing.
        if (($notice === null) !== ($locale === null)) {
            throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'disclosure_notice');
        }

        return new AiTransparencyPolicy(
            surfaceId: StoredValue::required($row->getString('surface_id'), self::TABLE, 'surface_id'),
            interactsWithNaturalPersons: $row->getBool('interacts_with_natural_persons'),
            disclosure: $notice === null || $locale === null
                ? null
                : new AiInteractionDisclosure($notice, $locale),
            generates: $this->hydrateKinds($row->getString('generates')),
            exemption: $this->hydrateExemption($row->getString('exemption')),
            publicCrimeReporting: $row->getBool('public_crime_reporting'),
        );
    }

    /**
     * @return list<SyntheticContentKind>
     */
    private function hydrateKinds(string $stored): array
    {
        $kinds = [];

        /** @var mixed $entry */
        foreach (StoredValue::decodeArray($stored, self::TABLE, 'generates') as $entry) {
            if (! is_string($entry)) {
                throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'generates');
            }

            // tryFrom rather than from, so a value outside Article 50(2)'s own
            // list of kinds surfaces as a corrupt governance record naming the
            // column instead of as a ValueError naming an enum.
            $kind = SyntheticContentKind::tryFrom($entry);

            if (! $kind instanceof SyntheticContentKind) {
                throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'generates');
            }

            $kinds[] = $kind;
        }

        return $kinds;
    }

    private function hydrateExemption(string $stored): TransparencyExemption
    {
        $exemption = TransparencyExemption::tryFrom($stored);

        if (! $exemption instanceof TransparencyExemption) {
            throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'exemption');
        }

        return $exemption;
    }

    /**
     * @param list<SyntheticContentKind> $kinds
     *
     * @return list<string>
     */
    private static function kindsToArray(array $kinds): array
    {
        $encoded = [];

        foreach ($kinds as $kind) {
            $encoded[] = $kind->value;
        }

        return $encoded;
    }
}
