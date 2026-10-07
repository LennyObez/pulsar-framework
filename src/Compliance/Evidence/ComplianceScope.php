<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;

use function is_array;
use function is_bool;
use function sprintf;

/**
 * What the operator asserts about the deployment's scope.
 *
 * The only genuinely new mechanism in the evidence set, and the only one,
 * because nothing in the tree records which data classes a deployment handles
 * and no code can derive it. No probe can know that a deployment stores no
 * cardholder data.
 *
 * Two properties make this a scoping decision rather than a hiding place:
 *
 *  - Silence means IN scope. An operator who says nothing gets every control
 *    assessed; scoping one out takes an explicit `false` in a named key. The
 *    opposite default would let an empty config file scope out a whole standard.
 *  - Every assertion is reproduced in the report with its config key and value,
 *    attributed to the operator. An assessor can falsify it in one question.
 *
 * An assertion is admissible for exactly one thing: carrying a control to
 * {@see \Pulsar\Compliance\Control\ControlOutcome::NotApplicable}, which
 * {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()} awards only on an
 * assertion that the subject is OUT of scope. Its grade is
 * {@see ObservationGrade::Asserted}, so it can never support Satisfied.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ComplianceScope
{
    /**
     * The config key under `scope` that carries each assertion, so the report can
     * print the exact line an assessor should ask about.
     *
     * @var array<string, string>
     */
    private const array CONFIG_KEYS = [
        ObservationId::ScopeStoresCardholderData->value => 'stores_cardholder_data',
        ObservationId::ScopeProcessesHealthData->value => 'processes_health_data',
        ObservationId::ScopeProcessesPersonalData->value => 'processes_personal_data',
    ];

    /**
     * Keyed by {@see ObservationId} value; every scope id is present after
     * construction, so {@see inScope()} is total.
     *
     * @var array<string, bool>
     */
    private array $assertions;

    /**
     * @param array<string, bool> $overrides Keyed by {@see ObservationId} value
     */
    public function __construct(array $overrides = [])
    {
        $assertions = [];

        foreach (self::CONFIG_KEYS as $id => $_key) {
            $assertions[$id] = $overrides[$id] ?? true;
        }

        $this->assertions = $assertions;
    }

    /**
     * Parse the `scope` block of config/compliance.php.
     *
     * Anything that is not an explicit boolean `false` leaves the subject IN
     * scope. A typo'd key, a string "no", a missing block: all of them keep the
     * control assessed, because a scoping claim that the framework had to guess
     * at is not a claim anyone signed.
     */
    #[NoDiscard]
    public static function fromArray(mixed $raw): self
    {
        if (!is_array($raw)) {
            return new self();
        }

        $overrides = [];

        foreach (self::CONFIG_KEYS as $id => $key) {
            /** @var mixed $value */
            $value = $raw[$key] ?? null;

            if (is_bool($value)) {
                $overrides[$id] = $value;
            }
        }

        return new self($overrides);
    }

    /**
     * Whether the subject of the given scope id is in scope for this deployment.
     */
    #[NoDiscard]
    public function inScope(ObservationId $id): bool
    {
        return $this->assertions[$id->value] ?? true;
    }

    /**
     * The operator's assertion about one scope id, as an observation.
     *
     * @throws UnknownScopeAssertionException when $id is not a scope id
     */
    #[NoDiscard]
    public function assertion(ObservationId $id): Observation
    {
        $key = self::CONFIG_KEYS[$id->value] ?? null;

        if ($key === null) {
            throw UnknownScopeAssertionException::forId($id);
        }

        $inScope = $this->assertions[$id->value] ?? true;
        $detail = sprintf(
            'config/compliance.php  scope.%s = %s — asserted by the operator%s',
            $key,
            $inScope ? 'true' : 'false',
            $inScope ? '' : '; the framework cannot verify this',
        );

        return $inScope
            ? Observation::assertedInScope($id, $detail, self::class)
            : Observation::assertedOutOfScope($id, $detail, self::class);
    }

    /**
     * Every scope assertion, one per scope id in {@see ObservationId}.
     *
     * @return list<Observation>
     */
    #[NoDiscard]
    public function assertions(): array
    {
        $observations = [];

        foreach (self::CONFIG_KEYS as $id => $_key) {
            $observationId = ObservationId::from($id);
            $observations[] = $this->assertion($observationId);
        }

        return $observations;
    }
}
