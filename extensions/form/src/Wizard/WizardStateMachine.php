<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Wizard;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Config\WizardFormConfig;
use Pulsar\Extension\Form\Exception\WizardException;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Session\SessionInterface;
use Throwable;

use function count;
use function is_string;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Multi-step wizard state machine with encrypted state.
 *
 * Manages step transitions, validates step order, enforces TTL,
 * and encrypts wizard state via the framework AEAD crypto module.
 *
 * State is stored server-side by default. Anti-replay protection
 * via monotonic step counter prevents re-submitting completed steps.
 * @api
 */
#[Api(since: '1.0.0')]
final class WizardStateMachine
{
    private const string SESSION_PREFIX = '_wizard_state_';

    /**
     * @param list<WizardStep> $steps
     */
    public function __construct(
        private readonly WizardFormConfig $config,
        private readonly EncryptorInterface $encryptor,
        private readonly SessionInterface $session,
        private readonly ResumeTokenManager $resumeTokenManager,
        private readonly array $steps,
    ) {}

    /**
     * Start a new wizard session.
     */
    public function start(string $wizardId): WizardState
    {
        $state = new WizardState(
            wizardId: $wizardId,
            currentStep: 0,
            stepCounter: 0,
            createdAt: time(),
            stepData: [],
        );

        $this->persist($state);

        return $state;
    }

    /**
     * Load the current wizard state.
     *
     * @throws WizardException When state is expired, corrupted, or missing
     */
    public function load(string $wizardId): WizardState
    {
        $key = self::SESSION_PREFIX . $wizardId;
        $encrypted = $this->session->get($key);

        if (!is_string($encrypted)) {
            throw WizardException::expired();
        }

        try {
            $json = $this->encryptor->decrypt($encrypted);
        } catch (Throwable) {
            throw WizardException::decryptionFailed();
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $state = WizardState::fromArray($data);

        // Check TTL
        if ((time() - $state->createdAt) > $this->config->ttl) {
            $this->session->remove($key);
            throw WizardException::expired();
        }

        return $state;
    }

    /**
     * Advance to the next step after validating data.
     *
     * @param array<string, mixed> $data Step submission data
     *
     * @throws WizardException When step replay detected or invalid transition
     */
    public function advance(WizardState $state, array $data): WizardState
    {
        $nextStep = $state->currentStep + 1;

        if ($nextStep >= count($this->steps)) {
            throw WizardException::invalidTransition($state->currentStep, $nextStep);
        }

        $newState = $state->advance($nextStep, $data);
        $this->persist($newState);

        return $newState;
    }

    /**
     * Go back to a previous step.
     *
     * @throws WizardException When transition is invalid
     */
    public function goBack(WizardState $state, int $targetStep): WizardState
    {
        if ($targetStep < 0 || $targetStep >= $state->currentStep) {
            throw WizardException::invalidTransition($state->currentStep, $targetStep);
        }

        $newState = $state->goBack($targetStep);
        $this->persist($newState);

        return $newState;
    }

    /**
     * Validate a step submission against replay protection.
     *
     * @throws WizardException When the step counter indicates replay
     */
    public function validateStepCounter(WizardState $state, int $submittedCounter): void
    {
        if ($submittedCounter < $state->stepCounter) {
            throw WizardException::stepReplay($submittedCounter);
        }
    }

    /**
     * Issue a resume token for this wizard session.
     */
    public function issueResumeToken(WizardState $state): string
    {
        return $this->resumeTokenManager->issue($state->wizardId);
    }

    /**
     * Resume a wizard using a resume token.
     *
     * @throws WizardException When token is invalid or expired
     */
    public function resume(string $wizardId, string $resumeToken): WizardState
    {
        $this->resumeTokenManager->consume($wizardId, $resumeToken);

        return $this->load($wizardId);
    }

    /**
     * Get the current step definition.
     */
    public function getCurrentStep(WizardState $state): WizardStep
    {
        return $this->steps[$state->currentStep];
    }

    /**
     * Get all step definitions.
     *
     * @return list<WizardStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Check if the wizard is on the final step.
     */
    public function isLastStep(WizardState $state): bool
    {
        return $state->currentStep >= count($this->steps) - 1;
    }

    /**
     * Complete the wizard and clean up state.
     *
     * @return array<int, array<string, mixed>> All collected step data
     */
    public function complete(WizardState $state): array
    {
        $key = self::SESSION_PREFIX . $state->wizardId;
        $this->session->remove($key);

        return $state->stepData;
    }

    /**
     * Encrypt and persist wizard state to the session.
     */
    private function persist(WizardState $state): void
    {
        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
        $encrypted = $this->encryptor->encrypt($json);

        $this->session->set(self::SESSION_PREFIX . $state->wizardId, $encrypted);
    }
}
