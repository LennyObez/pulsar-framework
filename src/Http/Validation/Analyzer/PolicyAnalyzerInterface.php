<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Analyzer;

use Pulsar\Api\Api;

/**
 * Analyzes field values against domain-specific policies.
 *
 * Returns findings that are structurally distinct from validation
 * violations. Findings carry severity, confidence, and recommendations
 * rather than pass/fail outcomes.
 * @api
 */
#[Api(since: '1.0.0')]
interface PolicyAnalyzerInterface
{
    /**
     * Analyze a field value and return findings.
     *
     * @param array<string, mixed> $data Full input data for cross-field analysis
     *
     * @return list<AnalyzerFinding>
     */
    public function analyze(string $field, mixed $value, array $data): array;
}
