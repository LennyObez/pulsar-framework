<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Wizard;

use Pulsar\Api\Internal;

use function is_array;
use function is_int;
use function is_string;

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
        $wizardId = isset($data['wizard_id']) && is_string($data['wizard_id']) ? $data['wizard_id'] : '';
        $currentStep = isset($data['current_step']) && is_int($data['current_step']) ? $data['current_step'] : 0;
        $stepCounter = isset($data['step_counter']) && is_int($data['step_counter']) ? $data['step_counter'] : 0;
        $createdAt = isset($data['created_at']) && is_int($data['created_at']) ? $data['created_at'] : 0;

        /** @var array<int, array<string, mixed>> $stepData */
        $stepData = isset($data['step_data']) && is_array($data['step_data']) ? $data['step_data'] : [];

        return new self(
            wizardId: $wizardId,
            currentStep: $currentStep,
            stepCounter: $stepCounter,
            createdAt: $createdAt,
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
