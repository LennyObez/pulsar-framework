<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Wizard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\WizardFormConfig;
use Pulsar\Extension\Form\Exception\WizardException;
use Pulsar\Extension\Form\Wizard\ResumeTokenManager;
use Pulsar\Extension\Form\Wizard\WizardState;
use Pulsar\Extension\Form\Wizard\WizardStateMachine;
use Pulsar\Extension\Form\Wizard\WizardStep;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Tests\Unit\Extension\Form\Stub\InMemorySession;
use RuntimeException;

use function strlen;

#[CoversClass(WizardStateMachine::class)]
final class WizardStateMachineDeepTest extends TestCase
{
    private InMemorySession $session;
    private EncryptorInterface&Stub $encryptor;
    private ResumeTokenManager $resumeTokenManager;

    protected function setUp(): void
    {
        $this->session = new InMemorySession();

        $this->encryptor = $this->createStub(EncryptorInterface::class);
        $this->encryptor->method('encrypt')->willReturnCallback(fn(string $data) => 'enc:' . $data);
        $this->encryptor->method('decrypt')->willReturnCallback(function (string $data) {
            if (!str_starts_with($data, 'enc:')) {
                throw new RuntimeException('Decryption failed');
            }
            return substr($data, 4);
        });

        $this->resumeTokenManager = new ResumeTokenManager($this->session);
    }

    private function createMachine(int $ttl = 3600, int $stepCount = 3): WizardStateMachine
    {
        $config = WizardFormConfig::fromArray(['ttl' => $ttl]);

        $steps = [];
        for ($i = 0; $i < $stepCount; $i++) {
            $steps[] = new WizardStep(index: $i, label: "Step $i", fields: []);
        }

        return new WizardStateMachine(
            config: $config,
            encryptor: $this->encryptor,
            session: $this->session,
            resumeTokenManager: $this->resumeTokenManager,
            steps: $steps,
        );
    }

    #[Test]
    public function startCreatesInitialState(): void
    {
        $machine = $this->createMachine();
        $state = $machine->start('wiz-1');

        self::assertSame('wiz-1', $state->wizardId);
        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
        self::assertSame([], $state->stepData);
    }

    #[Test]
    public function loadRetrievesPersistedState(): void
    {
        $machine = $this->createMachine();
        $machine->start('wiz-1');

        $loaded = $machine->load('wiz-1');

        self::assertSame('wiz-1', $loaded->wizardId);
        self::assertSame(0, $loaded->currentStep);
    }

    #[Test]
    public function loadThrowsWhenSessionMissing(): void
    {
        $machine = $this->createMachine();

        $this->expectException(WizardException::class);
        $machine->load('nonexistent');
    }

    #[Test]
    public function loadThrowsWhenDecryptionFails(): void
    {
        $this->session->set('_wizard_state_wiz-bad', 'corrupted-data');
        $machine = $this->createMachine();

        $this->expectException(WizardException::class);
        $machine->load('wiz-bad');
    }

    #[Test]
    public function loadThrowsWhenTtlExpired(): void
    {
        $machine = $this->createMachine(ttl: 1);

        // Create state with old timestamp
        $oldState = new WizardState(
            wizardId: 'wiz-old',
            currentStep: 0,
            stepCounter: 0,
            createdAt: time() - 100,
            stepData: [],
        );
        $json = json_encode($oldState->toArray(), JSON_THROW_ON_ERROR);
        $this->session->set('_wizard_state_wiz-old', 'enc:' . $json);

        $this->expectException(WizardException::class);
        $machine->load('wiz-old');
    }

    #[Test]
    public function advanceMovesToNextStep(): void
    {
        $machine = $this->createMachine(stepCount: 3);
        $state = $machine->start('wiz-1');

        $next = $machine->advance($state, ['name' => 'John']);

        self::assertSame(1, $next->currentStep);
        self::assertSame(['name' => 'John'], $next->stepData[0]);
    }

    #[Test]
    public function advanceThrowsWhenAlreadyOnLastStep(): void
    {
        $machine = $this->createMachine(stepCount: 2);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['data' => 'first']);

        // Now on step 1 (last step, 0-indexed for 2 steps), trying to advance to step 2
        $this->expectException(WizardException::class);
        $machine->advance($state, ['data' => 'second']);
    }

    #[Test]
    public function goBackReturnsToPreviousStep(): void
    {
        $machine = $this->createMachine(stepCount: 3);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['step0' => 'data']);

        $back = $machine->goBack($state, 0);

        self::assertSame(0, $back->currentStep);
    }

    #[Test]
    public function goBackThrowsForInvalidTarget(): void
    {
        $machine = $this->createMachine(stepCount: 3);
        $state = $machine->start('wiz-1');

        // Can't go back from step 0
        $this->expectException(WizardException::class);
        $machine->goBack($state, -1);
    }

    #[Test]
    public function goBackThrowsForForwardTarget(): void
    {
        $machine = $this->createMachine(stepCount: 3);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['data' => 'x']);

        // Can't go to step >= current
        $this->expectException(WizardException::class);
        $machine->goBack($state, 1);
    }

    #[Test]
    public function validateStepCounterThrowsOnReplay(): void
    {
        $machine = $this->createMachine();
        $state = new WizardState(
            wizardId: 'wiz-1',
            currentStep: 1,
            stepCounter: 5,
            createdAt: time(),
            stepData: [],
        );

        $this->expectException(WizardException::class);
        $machine->validateStepCounter($state, 3);
    }

    #[Test]
    public function validateStepCounterPassesWhenValid(): void
    {
        $this->expectNotToPerformAssertions();

        $machine = $this->createMachine();
        $state = new WizardState(
            wizardId: 'wiz-1',
            currentStep: 1,
            stepCounter: 5,
            createdAt: time(),
            stepData: [],
        );

        $machine->validateStepCounter($state, 5);
        $machine->validateStepCounter($state, 10);
    }

    #[Test]
    public function issueResumeTokenDelegatesToManager(): void
    {
        $machine = $this->createMachine();
        $state = $machine->start('wiz-1');

        $token = $machine->issueResumeToken($state);

        self::assertNotEmpty($token);
        self::assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    #[Test]
    public function getCurrentStepReturnsCorrectStep(): void
    {
        $machine = $this->createMachine(stepCount: 3);
        $state = $machine->start('wiz-1');

        $step = $machine->getCurrentStep($state);

        self::assertSame(0, $step->index);
        self::assertSame('Step 0', $step->label);
    }

    #[Test]
    public function getStepsReturnsAllSteps(): void
    {
        $machine = $this->createMachine(stepCount: 3);

        self::assertCount(3, $machine->getSteps());
    }

    #[Test]
    public function isLastStepReturnsTrueOnFinalStep(): void
    {
        $machine = $this->createMachine(stepCount: 2);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['data' => 'x']);

        self::assertTrue($machine->isLastStep($state));
    }

    #[Test]
    public function isLastStepReturnsFalseOnEarlierStep(): void
    {
        $machine = $this->createMachine(stepCount: 3);
        $state = $machine->start('wiz-1');

        self::assertFalse($machine->isLastStep($state));
    }

    #[Test]
    public function completeReturnsStepDataAndClearsSession(): void
    {
        $machine = $this->createMachine(stepCount: 2);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['name' => 'John']);

        $data = $machine->complete($state);

        self::assertSame([0 => ['name' => 'John']], $data);
        self::assertFalse($this->session->has('_wizard_state_wiz-1'));
    }
}
