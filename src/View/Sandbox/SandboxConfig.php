<?php

declare(strict_types=1);

namespace Pulsar\View\Sandbox;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewConfig;

/**
 * Configuration for the untrusted template sandbox.
 *
 * Controls resource bounds and the include allowlist.
 */
#[Internal(reason: 'Sandbox config is an implementation detail')]
final readonly class SandboxConfig
{
    /**
     * @param int $stepLimit Maximum AST node evaluations
     * @param int $loopLimit Maximum iterations per loop
     * @param int $outputSizeLimit Maximum rendered output bytes
     * @param int $wallClockCheckInterval Steps between wall-clock checks
     * @param float $wallClockLimitSeconds Maximum wall-clock time in seconds
     * @param array<string, string> $includeAllowlist Template ID → template content mapping
     */
    public function __construct(
        public int $stepLimit = 10_000,
        public int $loopLimit = 1_000,
        public int $outputSizeLimit = 1_048_576,
        public int $wallClockCheckInterval = 500,
        public float $wallClockLimitSeconds = 5.0,
        public array $includeAllowlist = [],
    ) {}

    /**
     * Build from a ViewConfig.
     */
    #[NoDiscard]
    public static function fromViewConfig(ViewConfig $config): self
    {
        return new self(
            stepLimit: $config->sandboxStepLimit,
            loopLimit: $config->sandboxLoopLimit,
            outputSizeLimit: $config->sandboxOutputSizeLimit,
            wallClockCheckInterval: $config->sandboxWallClockCheckInterval,
        );
    }

    /**
     * Check whether a template ID is in the include allowlist.
     */
    #[NoDiscard]
    public function isIncludeAllowed(string $templateId): bool
    {
        return isset($this->includeAllowlist[$templateId]);
    }

    /**
     * Get template content by ID from the allowlist.
     */
    #[NoDiscard]
    public function getIncludeContent(string $templateId): ?string
    {
        return $this->includeAllowlist[$templateId] ?? null;
    }
}
