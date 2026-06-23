<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Wizard;

use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Encrypted wizard state holding step data and anti-replay counter.
 */
#[Internal(reason: 'Wizard state is encrypted and not exposed to consumers')]
final readonly class WizardState
{
    /**
     * @param array<int, array<string, mixed>> $stepData Data collected per step
     */
    public function __construct(
        public string $wizardId,
        public int $currentStep,
        public int $stepCounter,
        public int $createdAt,
        public array $stepData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wizard_id' => $this->wizardId,
            'current_step' => $this->currentStep,
            'step_counter' => $this->stepCounter,
            'created_at' => $this->createdAt,
            'step_data' => $this->stepData,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var mixed $stepData */
        $stepData = $data['step_data'] ?? null;
        if (!is_array($stepData)) {
            $stepData = [];
        }
        /** @var array<int, array<string, mixed>> $stepData */

        return new self(
            wizardId: Coerce::string($data['wizard_id'] ?? null),
            currentStep: Coerce::int($data['current_step'] ?? null, 0),
            stepCounter: Coerce::int($data['step_counter'] ?? null, 0),
            createdAt: Coerce::int($data['created_at'] ?? null, 0),
            stepData: $stepData,
        );
    }

    /**
     * Advance to the next step with submitted data.
     *
     * @param array<string, mixed> $data
     */
    public function advance(int $nextStep, array $data): self
    {
        $stepData = $this->stepData;
        $stepData[$this->currentStep] = $data;

        return new self(
            wizardId: $this->wizardId,
            currentStep: $nextStep,
            stepCounter: $this->stepCounter + 1,
            createdAt: $this->createdAt,
            stepData: $stepData,
        );
    }

    /**
     * Go back to a previous step.
     */
    public function goBack(int $previousStep): self
    {
        return new self(
            wizardId: $this->wizardId,
            currentStep: $previousStep,
            stepCounter: $this->stepCounter,
            createdAt: $this->createdAt,
            stepData: $this->stepData,
        );
    }
}
