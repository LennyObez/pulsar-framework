<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Pipeline;

use Pulsar\Api\Api;

/**
 * Result of a CI/CD pipeline security audit.
 *
 * Reports which security tools are present or missing from workflows,
 * identifies security bypasses (e.g. --no-verify, continue-on-error),
 * and flags actions that are not SHA-pinned.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PipelineAuditResult
{
    /**
     * @param list<string> $presentTools Security tools found in workflows
     * @param list<string> $missingTools Required tools not found in any workflow
     * @param list<array{file: string, step: string, reason: string}> $bypasses Security bypass detections
     * @param list<array{file: string, action: string}> $unpinnedActions Actions using tags/branches instead of SHA
     * @param bool $isCompliant Whether all required tools are present and no bypasses exist
     */
    public function __construct(
        public array $presentTools,
        public array $missingTools,
        public array $bypasses,
        public array $unpinnedActions,
        public bool $isCompliant,
    ) {}
}
