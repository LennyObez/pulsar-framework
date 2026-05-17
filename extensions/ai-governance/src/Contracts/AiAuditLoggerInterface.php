<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;

/**
 * AI-specific audit logging contract.
 *
 * Extends the core audit logging pattern with AI governance events
 * per ISO 42001:2023 Clause 9.1 monitoring and measurement requirements.
 * Delegates to the core AuditLoggerInterface for tamper-evident storage.
 * @api
 */
#[Api(since: '1.0.0')]
interface AiAuditLoggerInterface
{
    /**
     * Log an AI governance event.
     *
     * @param string $modelId The AI model involved
     * @param string $action Description of the action
     * @param string $resource The resource or subject of the action
     * @param array<string, mixed> $metadata Additional context (sanitized inputs/outputs, confidence, etc.)
     */
    public function logAiEvent(
        AiAuditEvent $event,
        string $modelId,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): void;

    /**
     * Log a model invocation with sanitized input/output.
     *
     * @param non-empty-string $modelId The model that was invoked
     * @param array<string, mixed> $sanitizedInput Sanitized representation of the input
     * @param array<string, mixed> $sanitizedOutput Sanitized representation of the output
     * @param float $confidenceScore Model confidence in its output (0.0–1.0)
     */
    public function logInvocation(
        string $modelId,
        array $sanitizedInput,
        array $sanitizedOutput,
        float $confidenceScore,
    ): void;

    /**
     * Log a human override of an AI decision.
     *
     * @param non-empty-string $modelId The model whose decision was overridden
     * @param non-empty-string $decisionId The original decision identifier
     * @param non-empty-string $reason Justification for the override
     * @param non-empty-string|null $overriddenBy Identity of the person who overrode
     */
    public function logHumanOverride(
        string $modelId,
        string $decisionId,
        string $reason,
        ?string $overriddenBy = null,
    ): void;
}
