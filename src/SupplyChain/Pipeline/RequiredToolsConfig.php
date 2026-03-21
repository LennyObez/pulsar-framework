<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Pipeline;

use Pulsar\Api\Api;

/**
 * Configuration for required security tools in CI pipelines.
 *
 * Defines which tools must be present in at least one workflow
 * and the patterns used to detect them.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RequiredToolsConfig
{
    /** @var list<string> */
    public array $requiredTools;

    /** @var array<string, list<string>> Map of tool name to detection patterns */
    public array $detectionPatterns;

    /**
     * @param list<string>|null $requiredTools Override the default required tools list
     * @param array<string, list<string>>|null $detectionPatterns Override detection patterns
     */
    public function __construct(
        ?array $requiredTools = null,
        ?array $detectionPatterns = null,
    ) {
        $this->requiredTools = $requiredTools ?? [
            'phpstan',
            'psalm',
            'composer-audit',
            'deptrac',
        ];

        $this->detectionPatterns = $detectionPatterns ?? [
            'phpstan' => ['phpstan', 'phpstan analyse', 'phpstan analyze'],
            'psalm' => ['psalm', 'vendor/bin/psalm'],
            'composer-audit' => ['composer audit', 'composer vulnerability'],
            'deptrac' => ['deptrac', 'deptrac analyse', 'deptrac analyze'],
            'semgrep' => ['semgrep', 'semgrep scan', 'semgrep ci'],
        ];
    }
}
