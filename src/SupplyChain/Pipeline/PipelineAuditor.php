<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Pipeline;

use NoDiscard;
use Pulsar\Api\Api;

use function array_diff;
use function array_unique;
use function array_values;
use function basename;
use function glob;
use function is_array;
use function is_string;
use function preg_match;
use function str_contains;

/**
 * Audits CI/CD pipeline configurations for security compliance.
 *
 * Scans GitHub Actions workflow files to verify:
 * - All required security tools are present
 * - No security steps use bypass flags (--no-verify, continue-on-error)
 * - All GitHub Actions are SHA-pinned (not using mutable tags)
 */
#[Api(since: '1.0.0')]
final readonly class PipelineAuditor
{
    public function __construct(
        private RequiredToolsConfig $config = new RequiredToolsConfig(),
    ) {}

    /**
     * Audit workflow files and produce a compliance result.
     *
     * @param array<string, string> $workflowContents Map of filename to YAML content.
     *                                                 When empty, scans .github/workflows/ in the project root.
     * @param string $projectRoot Absolute path to project root (used when $workflowContents is empty)
     */
    #[NoDiscard]
    public function audit(array $workflowContents = [], string $projectRoot = ''): PipelineAuditResult
    {
        if ($workflowContents === [] && $projectRoot !== '') {
            $workflowContents = $this->loadWorkflows($projectRoot);
        }

        $presentTools = [];
        $bypasses = [];
        $unpinnedActions = [];

        foreach ($workflowContents as $filename => $content) {
            $this->detectTools($content, $presentTools);
            $this->detectBypasses($filename, $content, $bypasses);
            $this->detectUnpinnedActions($filename, $content, $unpinnedActions);
        }

        $presentTools = array_values(array_unique($presentTools));
        $missingTools = array_values(array_diff($this->config->requiredTools, $presentTools));

        $isCompliant = $missingTools === [] && $bypasses === [] && $unpinnedActions === [];

        return new PipelineAuditResult(
            presentTools: $presentTools,
            missingTools: $missingTools,
            bypasses: $bypasses,
            unpinnedActions: $unpinnedActions,
            isCompliant: $isCompliant,
        );
    }

    /**
     * Load workflow YAML files from the project's .github/workflows/ directory.
     *
     * @return array<string, string>
     */
    private function loadWorkflows(string $projectRoot): array
    {
        $pattern = $projectRoot . '/.github/workflows/*.yml';
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        $contents = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if (is_string($content)) {
                $contents[basename($file)] = $content;
            }
        }

        // Also check .yaml extension
        $yamlPattern = $projectRoot . '/.github/workflows/*.yaml';
        $yamlFiles = glob($yamlPattern);

        if (is_array($yamlFiles)) {
            foreach ($yamlFiles as $file) {
                $content = file_get_contents($file);

                if (is_string($content)) {
                    $contents[basename($file)] = $content;
                }
            }
        }

        return $contents;
    }

    /**
     * Detect which security tools are referenced in workflow content.
     *
     * @param list<string> $presentTools Accumulator (modified by reference)
     */
    private function detectTools(string $content, array &$presentTools): void
    {
        $lowerContent = strtolower($content);

        foreach ($this->config->detectionPatterns as $tool => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($lowerContent, strtolower($pattern))) {
                    $presentTools[] = $tool;

                    break;
                }
            }
        }
    }

    /**
     * Detect security bypasses in workflow content.
     *
     * @param list<array{file: string, step: string, reason: string}> $bypasses Accumulator
     */
    private function detectBypasses(string $filename, string $content, array &$bypasses): void
    {
        $lines = explode("\n", $content);
        $currentStep = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Track step names
            if (preg_match('/^-?\s*name:\s*["\']?(.+?)["\']?\s*$/', $trimmed, $matches) === 1) {
                $currentStep = $matches[1];
            }

            // Detect continue-on-error on security-related steps
            if (str_contains($trimmed, 'continue-on-error:') && str_contains($trimmed, 'true')) {
                $bypasses[] = [
                    'file' => $filename,
                    'step' => $currentStep,
                    'reason' => 'continue-on-error: true allows security step failures to be silently ignored',
                ];
            }

            // Detect --no-verify flags
            if (str_contains($trimmed, '--no-verify')) {
                $bypasses[] = [
                    'file' => $filename,
                    'step' => $currentStep,
                    'reason' => '--no-verify flag bypasses security verification hooks',
                ];
            }
        }
    }

    /**
     * Detect GitHub Actions that are not SHA-pinned.
     *
     * A properly pinned action uses a full 40-char SHA: `uses: owner/repo@<sha>`.
     * Tag references like `@v4` or `@main` are mutable and vulnerable to supply chain attacks.
     *
     * @param list<array{file: string, action: string}> $unpinnedActions Accumulator
     */
    private function detectUnpinnedActions(string $filename, string $content, array &$unpinnedActions): void
    {
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Match `uses:` directives — handles both `uses:` and `- uses:` forms
            if (preg_match('/^-?\s*uses:\s*["\']?([^"\'\s]+)["\']?/', $trimmed, $matches) !== 1) {
                continue;
            }

            $action = $matches[1];

            // Skip local composite actions
            if (str_starts_with($action, './')) {
                continue;
            }

            // Check if the action reference includes an @ with a SHA (40 hex chars)
            if (preg_match('/@([a-f0-9]{40})$/', $action) === 1) {
                continue;
            }

            $unpinnedActions[] = [
                'file' => $filename,
                'action' => $action,
            ];
        }
    }
}
