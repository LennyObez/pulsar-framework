<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Wizard;

use Pulsar\Api\Internal;

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
     * @param array{
     *     wizard_id?: string,
     *     current_step?: int,
     *     step_counter?: int,
     *     created_at?: int,
     *     step_data?: array<int, array<string, mixed>>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            wizardId: $data['wizard_id'] ?? '',
            currentStep: $data['current_step'] ?? 0,
            stepCounter: $data['step_counter'] ?? 0,
            createdAt: $data['created_at'] ?? 0,
            stepData: $data['step_data'] ?? [],
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
