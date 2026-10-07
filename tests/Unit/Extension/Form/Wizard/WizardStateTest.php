<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Wizard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Wizard\WizardState;

#[CoversClass(WizardState::class)]
final class WizardStateTest extends TestCase
{
    #[Test]
    public function toArrayReturnsAllFields(): void
    {
        $state = new WizardState(
            wizardId: 'wiz-1',
            currentStep: 2,
            stepCounter: 3,
            createdAt: 1700000000,
            stepData: [0 => ['name' => 'Alice'], 1 => ['email' => 'a@b.com']],
        );

        $array = $state->toArray();

        self::assertSame('wiz-1', $array['wizard_id']);
        self::assertSame(2, $array['current_step']);
        self::assertSame(3, $array['step_counter']);
        self::assertSame(1700000000, $array['created_at']);
        self::assertSame([0 => ['name' => 'Alice'], 1 => ['email' => 'a@b.com']], $array['step_data']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = new WizardState(
            wizardId: 'wiz-2',
            currentStep: 1,
            stepCounter: 2,
            createdAt: 1700000100,
            stepData: [0 => ['field' => 'value']],
        );

        $restored = WizardState::fromArray($original->toArray());

        self::assertSame($original->wizardId, $restored->wizardId);
        self::assertSame($original->currentStep, $restored->currentStep);
        self::assertSame($original->stepCounter, $restored->stepCounter);
        self::assertSame($original->createdAt, $restored->createdAt);
        self::assertSame($original->stepData, $restored->stepData);
    }

    #[Test]
    public function fromArrayHandlesMissingKeys(): void
    {
        $state = WizardState::fromArray([]);

        self::assertSame('', $state->wizardId);
        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
        self::assertSame(0, $state->createdAt);
        self::assertSame([], $state->stepData);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $state = WizardState::fromArray([
            'wizard_id' => 42,
            'current_step' => 'two',
            'step_counter' => false,
            'created_at' => null,
            'step_data' => 'not-array',
        ]);

        self::assertSame('', $state->wizardId);
        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
        self::assertSame(0, $state->createdAt);
        self::assertSame([], $state->stepData);
    }

    #[Test]
    public function advanceReturnsNewStateWithNextStep(): void
    {
        $state = new WizardState(
            wizardId: 'wiz',
            currentStep: 0,
            stepCounter: 0,
            createdAt: 1700000000,
            stepData: [],
        );

        $next = $state->advance(1, ['name' => 'Alice']);

        self::assertSame('wiz', $next->wizardId);
        self::assertSame(1, $next->currentStep);
        self::assertSame(1, $next->stepCounter);
        self::assertSame(1700000000, $next->createdAt);
        self::assertSame([0 => ['name' => 'Alice']], $next->stepData);
    }

    #[Test]
    public function advancePreservesExistingStepData(): void
    {
        $state = new WizardState(
            wizardId: 'wiz',
            currentStep: 1,
            stepCounter: 1,
            createdAt: 1700000000,
            stepData: [0 => ['x' => 'y']],
        );

        $next = $state->advance(2, ['a' => 'b']);

        self::assertSame([0 => ['x' => 'y'], 1 => ['a' => 'b']], $next->stepData);
        self::assertSame(2, $next->stepCounter);
    }

    #[Test]
    public function goBackReturnsNewStateWithPreviousStep(): void
    {
        $state = new WizardState(
            wizardId: 'wiz',
            currentStep: 2,
            stepCounter: 3,
            createdAt: 1700000000,
            stepData: [0 => ['x' => '1'], 1 => ['y' => '2']],
        );

        $prev = $state->goBack(1);

        self::assertSame(1, $prev->currentStep);
        self::assertSame(3, $prev->stepCounter); // counter preserved
        self::assertSame($state->stepData, $prev->stepData); // data preserved
    }

    #[Test]
    public function advanceDoesNotMutateOriginal(): void
    {
        $state = new WizardState('wiz', 0, 0, 1700000000, []);
        $next = $state->advance(1, ['k' => 'v']);

        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
        self::assertSame([], $state->stepData);
        self::assertSame(1, $next->currentStep);
    }

    #[Test]
    public function goBackDoesNotMutateOriginal(): void
    {
        $state = new WizardState('wiz', 2, 2, 1700000000, []);
        $prev = $state->goBack(0);

        self::assertSame(2, $state->currentStep);
        self::assertSame(0, $prev->currentStep);
    }
}
