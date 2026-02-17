<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ThreatDetection\ThreatCategory;

/**
 * Result of executing an incident response playbook.
 */
#[Api(since: '1.0.0')]
final readonly class PlaybookResult
{
    /**
     * @param list<string> $executedSteps
     */
    private function __construct(
        public ThreatCategory $category,
        public PlaybookOutcome $outcome,
        public array $executedSteps,
        public string $failedStep = '',
        public string $errorMessage = '',
    ) {}

    /**
     * @param list<string> $executedSteps
     */
    #[NoDiscard]
    public static function completed(ThreatCategory $category, array $executedSteps, bool $halted): self
    {
        return new self(
            category: $category,
            outcome: $halted ? PlaybookOutcome::Halted : PlaybookOutcome::Completed,
            executedSteps: $executedSteps,
        );
    }

    #[NoDiscard]
    public static function noPlaybook(ThreatCategory $category): self
    {
        return new self(
            category: $category,
            outcome: PlaybookOutcome::NoPlaybook,
            executedSteps: [],
        );
    }

    /**
     * @param list<string> $executedSteps
     */
    #[NoDiscard]
    public static function error(ThreatCategory $category, array $executedSteps, string $failedStep, string $message): self
    {
        return new self(
            category: $category,
            outcome: PlaybookOutcome::Error,
            executedSteps: $executedSteps,
            failedStep: $failedStep,
            errorMessage: $message,
        );
    }

    public function succeeded(): bool
    {
        return $this->outcome === PlaybookOutcome::Completed || $this->outcome === PlaybookOutcome::Halted;
    }
}
