<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;

/**
 * Which concrete class answered each contract, as the composition root found it.
 *
 * The container never reaches the compliance module. The composition root — the
 * only place allowed to resolve anything — asks it once per contract and records
 * `$instance::class`; what travels here is that answer, a class name, not the
 * power to ask more questions. A gatherer holding a container would be a service
 * locator, and a gatherer that could resolve on demand could ask the one question
 * ADR-0041 proved worthless: "can this be constructed?"
 *
 * A contract that nothing answered is simply absent. That is how a fact about a
 * primitive the framework does not have — NIST CSF RC.RP's backup and restore —
 * is expressible at all: the contract name is asked for, nothing answers, and the
 * control reports a gap instead of the mapping asserting coverage in prose.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ResolvedBindings
{
    /**
     * @param array<string, class-string> $resolved Contract identifier => the concrete
     *        class that answered it. Contracts nothing answered are absent, never null.
     */
    public function __construct(
        private array $resolved = [],
    ) {}

    /**
     * The class that answered $contract, or null when nothing did.
     *
     * @return class-string|null
     */
    #[NoDiscard]
    public function concreteFor(string $contract): ?string
    {
        return $this->resolved[$contract] ?? null;
    }

    #[NoDiscard]
    public function answered(string $contract): bool
    {
        return array_key_exists($contract, $this->resolved);
    }

    /**
     * @return array<string, class-string>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->resolved;
    }
}
