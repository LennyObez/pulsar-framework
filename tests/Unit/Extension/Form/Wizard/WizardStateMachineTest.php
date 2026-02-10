<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Wizard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\WizardFormConfig;
use Pulsar\Extension\Form\Exception\WizardException;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Wizard\ResumeTokenManager;
use Pulsar\Extension\Form\Wizard\WizardState;
use Pulsar\Extension\Form\Wizard\WizardStateMachine;
use Pulsar\Extension\Form\Wizard\WizardStep;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Tests\Unit\Extension\Form\Stub\InMemorySession;
use RuntimeException;

#[CoversClass(WizardStateMachine::class)]
#[CoversClass(WizardState::class)]
#[CoversClass(WizardStep::class)]
#[CoversClass(ResumeTokenManager::class)]
final class WizardStateMachineTest extends TestCase
{
    private InMemorySession $session;
    private WizardStateMachine $wizard;

    protected function setUp(): void
    {
        $this->session = new InMemorySession();
        $config = WizardFormConfig::fromArray(['ttl' => 3600]);
        $encryptor = $this->createFakeEncryptor();
        $resumeTokenManager = new ResumeTokenManager($this->session);

        $steps = [
            new WizardStep(0, 'Personal Info', ['name' => new TextField('name', 'Name')]),
            new WizardStep(1, 'Address', ['street' => new TextField('street', 'Street')]),
            new WizardStep(2, 'Confirm', []),
        ];

        $this->wizard = new WizardStateMachine($config, $encryptor, $this->session, $resumeTokenManager, $steps);
    }

    #[Test]
    public function it_starts_a_new_wizard(): void
    {
        $state = $this->wizard->start('wizard-1');

        self::assertSame('wizard-1', $state->wizardId);
        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
        self::assertSame([], $state->stepData);
    }

    #[Test]
    public function it_loads_wizard_state(): void
    {
        $this->wizard->start('wizard-1');
        $loaded = $this->wizard->load('wizard-1');

        self::assertSame('wizard-1', $loaded->wizardId);
        self::assertSame(0, $loaded->currentStep);
    }

    #[Test]
    public function it_advances_to_next_step(): void
    {
        $state = $this->wizard->start('wizard-1');
        $newState = $this->wizard->advance($state, ['name' => 'John']);

        self::assertSame(1, $newState->currentStep);
        self::assertSame(1, $newState->stepCounter);
        self::assertSame(['name' => 'John'], $newState->stepData[0]);
    }

    #[Test]
    public function it_prevents_advancing_past_last_step(): void
    {
        $state = $this->wizard->start('wizard-1');
        $state = $this->wizard->advance($state, ['name' => 'John']);
        $state = $this->wizard->advance($state, ['street' => '123 Main']);

        $this->expectException(WizardException::class);
        $this->wizard->advance($state, []);
    }

    #[Test]
    public function it_supports_going_back(): void
    {
        $state = $this->wizard->start('wizard-1');
        $state = $this->wizard->advance($state, ['name' => 'John']);

        $backState = $this->wizard->goBack($state, 0);
        self::assertSame(0, $backState->currentStep);
    }

    #[Test]
    public function it_prevents_invalid_back_navigation(): void
    {
        $state = $this->wizard->start('wizard-1');

        $this->expectException(WizardException::class);
        $this->wizard->goBack($state, -1);
    }

    #[Test]
    public function it_detects_step_replay(): void
    {
        $state = $this->wizard->start('wizard-1');
        $state = $this->wizard->advance($state, ['name' => 'John']);

        $this->expectException(WizardException::class);
        $this->expectExceptionMessage('already been completed');
        $this->wizard->validateStepCounter($state, 0);
    }

    #[Test]
    public function it_completes_wizard_and_returns_data(): void
    {
        $state = $this->wizard->start('wizard-1');
        $state = $this->wizard->advance($state, ['name' => 'John']);
        $state = $this->wizard->advance($state, ['street' => '123 Main']);

        $data = $this->wizard->complete($state);

        self::assertSame(['name' => 'John'], $data[0]);
        self::assertSame(['street' => '123 Main'], $data[1]);
    }

    #[Test]
    public function it_checks_last_step(): void
    {
        $state = $this->wizard->start('wizard-1');
        self::assertFalse($this->wizard->isLastStep($state));

        $state = $this->wizard->advance($state, []);
        self::assertFalse($this->wizard->isLastStep($state));

        $state = $this->wizard->advance($state, []);
        self::assertTrue($this->wizard->isLastStep($state));
    }

    #[Test]
    public function it_issues_and_consumes_resume_tokens(): void
    {
        $state = $this->wizard->start('wizard-1');
        $token = $this->wizard->issueResumeToken($state);

        self::assertNotEmpty($token);

        $resumed = $this->wizard->resume('wizard-1', $token);
        self::assertSame('wizard-1', $resumed->wizardId);
    }

    #[Test]
    public function resume_tokens_are_single_use(): void
    {
        $state = $this->wizard->start('wizard-1');
        $token = $this->wizard->issueResumeToken($state);

        $this->wizard->resume('wizard-1', $token);

        $this->expectException(WizardException::class);
        $this->expectExceptionMessage('invalid or has already been used');
        $this->wizard->resume('wizard-1', $token);
    }

    #[Test]
    public function it_rejects_expired_wizard_state(): void
    {
        $config = WizardFormConfig::fromArray(['ttl' => 0]);
        $encryptor = $this->createFakeEncryptor();
        $wizard = new WizardStateMachine(
            $config,
            $encryptor,
            $this->session,
            new ResumeTokenManager($this->session),
            [new WizardStep(0, 'Step', [])],
        );

        $wizard->start('wizard-ttl');
        sleep(1);

        $this->expectException(WizardException::class);
        $this->expectExceptionMessage('expired');
        $wizard->load('wizard-ttl');
    }

    #[Test]
    public function wizard_state_serializes_and_deserializes(): void
    {
        $state = new WizardState(
            wizardId: 'test',
            currentStep: 2,
            stepCounter: 3,
            createdAt: 1000000,
            stepData: [0 => ['a' => 'b']],
        );

        $array = $state->toArray();
        $restored = WizardState::fromArray($array);

        self::assertSame($state->wizardId, $restored->wizardId);
        self::assertSame($state->currentStep, $restored->currentStep);
        self::assertSame($state->stepCounter, $restored->stepCounter);
        self::assertSame($state->createdAt, $restored->createdAt);
    }

    #[Test]
    public function it_gets_current_step_and_all_steps(): void
    {
        $state = $this->wizard->start('wizard-1');
        $currentStep = $this->wizard->getCurrentStep($state);

        self::assertSame('Personal Info', $currentStep->label);
        self::assertCount(3, $this->wizard->getSteps());
    }

    /**
     * Create a fake encryptor that base64-encodes instead of truly encrypting.
     */
    private function createFakeEncryptor(): EncryptorInterface
    {
        return new class implements EncryptorInterface {
            public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }

            public function decrypt(string $encoded): string
            {
                $result = base64_decode($encoded, true);

                if ($result === false) {
                    throw new RuntimeException('Decryption failed');
                }

                return $result;
            }

            public function withDerivedKey(\Pulsar\Security\Crypto\MasterKey $masterKey, int $subKeyId, string $context): self
            {
                return $this;
            }
        };
    }
}
