<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;

/**
 * Options passed to an LLM provider for completion requests.
 *
 * @psalm-api Public DTO consumed by AI clients and extension code; class-level
 *            marker for findUnusedCode analysis.
 */
#[Api(since: '1.0.0')]
final readonly class LlmOptions
{
    public function __construct(
        public float $temperature = 0.7,
        public int $maxTokens = 1024,
        public ?string $systemPrompt = null,
        public int $timeoutSeconds = 30,
        public bool $auditLog = true,
        public ?int $maxCostCents = null,
        public ?string $usageCategory = null,
    ) {}
}
