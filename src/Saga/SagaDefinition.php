<?php

declare(strict_types=1);

namespace Pulsar\Saga;

use Pulsar\Api\Api;
use Pulsar\Saga\Exception\SagaException;
use Pulsar\Saga\Step\SagaStep;

use function array_key_exists;
use function array_keys;
use function count;

/**
 * Immutable definition of a saga — a sequence of steps with forward and
 * compensation actions.
 *
 * Steps are ordered: they execute sequentially in the forward direction
 * and compensate in reverse order on failure.
 */
#[Api(since: '1.0.0')]
final readonly class SagaDefinition
{
    /** @var array<string, SagaStep> Steps indexed by name, in execution order */
    public array $steps;

    /**
     * @param non-empty-string         $name
     * @param array<string, SagaStep>  $steps    Steps indexed by name, in execution order
     * @param array<string, mixed>     $metadata Arbitrary domain metadata
     */
    public function __construct(
        public string $name,
        array $steps,
        public array $metadata = [],
    ) {
        $this->steps = $steps;
    }

    /**
     * Get the total number of steps.
     *
     * @return int<0, max>
     */
    public function stepCount(): int
    {
        return count($this->steps);
    }

    /**
     * Get a step by name.
     *
     * @throws SagaException If the step does not exist
     */
    public function getStep(string $name): SagaStep
    {
        if (!array_key_exists($name, $this->steps)) {
            throw SagaException::stepFailed('(definition)', $name, 'step not found in definition');
        }

        return $this->steps[$name];
    }

    /**
     * Get step at a given index position.
     *
     * @param int<0, max> $index
     *
     * @throws SagaException If the index is out of bounds
     */
    public function getStepAtIndex(int $index): SagaStep
    {
        $names = array_keys($this->steps);

        if (!isset($names[$index])) {
            throw SagaException::stepFailed(
                '(definition)',
                (string) $index,
                'step index out of bounds',
            );
        }

        return $this->steps[$names[$index]];
    }

    /**
     * Get all step names in execution order.
     *
     * @return list<string>
     */
    public function getStepNames(): array
    {
        return array_keys($this->steps);
    }

    /**
     * Validate the definition structure.
     *
     * @throws SagaException If no steps are defined
     */
    public function validate(): void
    {
        if ($this->steps === []) {
            throw SagaException::noStepsDefined($this->name);
        }
    }
}
