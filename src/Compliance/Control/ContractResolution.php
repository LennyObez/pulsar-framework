<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;
use function sprintf;

/**
 * Which concrete class answered one contract, judged against what this release
 * accepts as discharging it.
 *
 * The material behind {@see ObservationGrade::Resolved}, and — since the grade
 * was downgraded — material for CONTEXT rather than for proof. What this class
 * computes, stated without decoration, is `in_array($concrete, $accepts)`: which
 * class is bound, and is it on the allow-list. That is ADR-0041's defect written
 * in the vocabulary built to forbid it, and the answer is not to make the
 * arithmetic cleverer. The answer is that {@see ObservationGrade::provesBehaviour()}
 * no longer returns true for Resolved, so no control can be satisfied by it.
 *
 * What it is still worth producing: a report that prints
 * `TokenStoreInterface -> InMemoryTokenStore; tokens are held in process memory`
 * tells an assessor which class to go and look at, and an accept list makes an
 * unassessed implementation read as unassessed instead of as adequate. Both are
 * real value in a document a human reads. Neither is evidence that anything ran.
 *
 * ON `$concrete` BEING A STRING. Review is right that reading the class off an
 * instance would be stronger than accepting its name, and a previous brief
 * forbade this signature by name. It stays a string for a reason that is not
 * convenience: {@see \Pulsar\Compliance\Evidence\ResolvedBindings} deliberately
 * never resolves the services it reports on. Half of the contracts in the accept
 * lists belong to extensions Compliance may not import, and one of them names a
 * primitive the framework does not have at all; instantiating them to prove a
 * name would boot subsystems during a report and would make the report's own
 * side effects part of what it measures. The honest fix is the one applied to the
 * grade: a resolved name proves nothing, so it does not matter very much how
 * faithfully it was obtained — and {@see answeredBy()} refuses every caller that
 * is not the component that measures, so the name cannot be invented either.
 *
 * The constructor is private and the value refuses deserialization, cloning and
 * `var_export` round-tripping; see {@see SealedValue}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ContractResolution
{
    use SealedValue;

    /**
     * @param string                $contract
     * @param string                $role
     * @param class-string|null     $concrete
     * @param list<string>          $accepts
     * @param array<string, string> $inert
     */
    private function __construct(
        public string $contract,
        public string $role,
        public ?string $concrete,
        private array $accepts,
        private array $inert,
    ) {}

    /**
     * A concrete class answered the contract.
     *
     * @param string                $contract The contract asked for
     * @param string                $role     What a class answering it does, in one clause,
     *                                        so a negative reads as a loss rather than a name
     * @param class-string          $concrete The class that answered, by name. A name, not an
     *        instance, and the class docblock says why that is defensible now and was not before
     *        the grade was downgraded
     * @param list<string>          $accepts  The implementations this release has assessed as
     *                                        discharging the contract. An accept list, so an
     *                                        implementation nobody assessed is reported
     *                                        unobserved rather than assumed adequate
     * @param array<string, string> $inert    Implementations known NOT to discharge it, mapped
     *                                        to why, so the report can say what stood there
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function answeredBy(
        string $contract,
        string $role,
        string $concrete,
        array $accepts,
        array $inert = [],
    ): self {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($contract, $role, $concrete, $accepts, $inert);
    }

    /**
     * Nothing answered the contract.
     *
     * Never discharged. This is how the absence of a primitive the framework does
     * not have becomes a stated fact instead of a silence.
     *
     * @param string           $contract
     * @param string           $role
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function unanswered(string $contract, string $role): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($contract, $role, null, [], []);
    }

    /**
     * Whether the class that answered is one this release accepts.
     *
     * Decides an observation's `present` flag at grade Resolved, and therefore
     * decides how the fact READS in the report — never whether a control holds.
     * See {@see ObservationGrade::provesBehaviour()}.
     */
    #[NoDiscard]
    public function discharged(): bool
    {
        return $this->concrete !== null && in_array($this->concrete, $this->accepts, true);
    }

    /**
     * The evidence line, generated from the resolution rather than written beside it.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public function detail(): string
    {
        if ($this->concrete === null) {
            return sprintf(
                'Nothing answered %s, so nothing in this deployment %s.',
                $this->contract,
                $this->role,
            );
        }

        if ($this->discharged()) {
            return sprintf('%s -> %s, which %s.', $this->contract, $this->concrete, $this->role);
        }

        $reason = $this->inert[$this->concrete] ?? null;

        return $reason !== null
            ? sprintf('%s -> %s; %s.', $this->contract, $this->concrete, $reason)
            : sprintf(
                '%s -> %s, which this release has not assessed as an implementation that %s; '
                    . 'it is reported unobserved rather than assumed adequate.',
                $this->contract,
                $this->concrete,
                $this->role,
            );
    }
}
