<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Pipeline;

use NoDiscard;
use Pulsar\Api\Api;

use function array_values;
use function is_array;
use function is_string;

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

    /**
     * Build from config/supply-chain.php. A missing or malformed
     * `required_pipeline_tools` key falls back to the default required-tools
     * list; the optional `detection_patterns` key overrides the built-in
     * detection patterns when it is a well-formed `array<string, list<string>>`.
     *
     * @param array<string, mixed> $data The raw config/supply-chain.php array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $tools */
        $tools = $data['required_pipeline_tools'] ?? null;
        /** @var mixed $patterns */
        $patterns = $data['detection_patterns'] ?? null;

        return new self(
            is_array($tools) ? array_values(array_filter($tools, 'is_string')) : null,
            is_array($patterns) ? self::normalizePatterns($patterns) : null,
        );
    }

    /**
     * Keep only entries with a string tool name mapped to a list of strings.
     *
     * @param array<array-key, mixed> $patterns
     * @return array<string, list<string>>
     */
    private static function normalizePatterns(array $patterns): array
    {
        $normalized = [];

        /** @var mixed $value */
        foreach ($patterns as $tool => $value) {
            if (!is_string($tool) || !is_array($value)) {
                continue;
            }

            $normalized[$tool] = array_values(array_filter($value, 'is_string'));
        }

        return $normalized;
    }
}
