<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Wizard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Wizard\WizardState;

#[CoversClass(WizardState::class)]
final class WizardStateDeepTest extends TestCase
{
    #[Test]
    public function fromArrayWithNonStringWizardId(): void
    {
        $state = WizardState::fromArray([
            'wizard_id' => 123,
            'current_step' => 1,
            'step_counter' => 2,
            'created_at' => 1000,
            'step_data' => [],
        ]);

        self::assertSame('', $state->wizardId);
    }

    #[Test]
    public function fromArrayWithNonIntCurrentStep(): void
    {
        $state = WizardState::fromArray([
            'wizard_id' => 'wiz-1',
            'current_step' => 'bad',
            'step_counter' => 2,
            'created_at' => 1000,
            'step_data' => [],
        ]);

        self::assertSame(0, $state->currentStep);
    }

    #[Test]
    public function fromArrayWithNonIntStepCounter(): void
    {
        $state = WizardState::fromArray([
            'wizard_id' => 'wiz-1',
            'current_step' => 1,
            'step_counter' => 'bad',
            'created_at' => 1000,
            'step_data' => [],
        ]);

        self::assertSame(0, $state->stepCounter);
    }

    #[Test]
    public function fromArrayWithNonIntCreatedAt(): void
    {
        $state = WizardState::fromArray([
            'wizard_id' => 'wiz-1',
            'current_step' => 1,
            'step_counter' => 2,
            'created_at' => 'bad',
            'step_data' => [],
        ]);

        self::assertSame(0, $state->createdAt);
    }

    #[Test]
    public function fromArrayWithNonArrayStepData(): void
    {
        $state = WizardState::fromArray([
            'wizard_id' => 'wiz-1',
            'current_step' => 1,
            'step_counter' => 2,
            'created_at' => 1000,
            'step_data' => 'bad',
        ]);

        self::assertSame([], $state->stepData);
    }

    #[Test]
    public function fromArrayWithMissingKeys(): void
    {
        $state = WizardState::fromArray([]);

        self::assertSame('', $state->wizardId);
        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
        self::assertSame(0, $state->createdAt);
        self::assertSame([], $state->stepData);
    }

    #[Test]
    public function toArrayRoundTrip(): void
    {
        $original = new WizardState(
            wizardId: 'wiz-42',
            currentStep: 2,
            stepCounter: 5,
            createdAt: 1700000000,
            stepData: [0 => ['name' => 'John'], 1 => ['email' => 'j@t.com']],
        );

        $restored = WizardState::fromArray($original->toArray());

        self::assertSame($original->wizardId, $restored->wizardId);
        self::assertSame($original->currentStep, $restored->currentStep);
        self::assertSame($original->stepCounter, $restored->stepCounter);
        self::assertSame($original->createdAt, $restored->createdAt);
        self::assertSame($original->stepData, $restored->stepData);
    }

    #[Test]
    public function advanceStoresCurrentStepDataAndIncrementsCounter(): void
    {
        $state = new WizardState(
            wizardId: 'wiz-1',
            currentStep: 0,
            stepCounter: 3,
            createdAt: 1000,
            stepData: [],
        );

        $next = $state->advance(1, ['field' => 'value']);

        self::assertSame(1, $next->currentStep);
        self::assertSame(4, $next->stepCounter);
        self::assertSame(['field' => 'value'], $next->stepData[0]);
        self::assertSame('wiz-1', $next->wizardId);
        self::assertSame(1000, $next->createdAt);
    }

    #[Test]
    public function goBackPreservesCounterAndData(): void
    {
        $state = new WizardState(
            wizardId: 'wiz-1',
            currentStep: 3,
            stepCounter: 7,
            createdAt: 1000,
            stepData: [0 => ['a' => 'b'], 1 => ['c' => 'd']],
        );

        $back = $state->goBack(1);

        self::assertSame(1, $back->currentStep);
        self::assertSame(7, $back->stepCounter);
        self::assertSame($state->stepData, $back->stepData);
    }
}
