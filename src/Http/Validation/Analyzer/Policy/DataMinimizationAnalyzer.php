<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Analyzer\Policy;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Analyzer\AnalyzerFinding;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\PolicyAnalyzerInterface;

use function preg_match;
use function sprintf;
use function strtolower;

/**
 * Flags fields with names suggesting unnecessary PII collection.
 *
 * Advisory only: not a compliance gate. This analyzer checks field names
 * against patterns that indicate potentially unnecessary personally identifiable
 * information collection (e.g., maiden names, full SSNs). Results should be
 * reviewed by qualified privacy personnel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DataMinimizationAnalyzer implements PolicyAnalyzerInterface
{
    /** @var list<array{pattern: string, description: string, severity: string, confidence: float}> */
    private const array PII_INDICATORS = [
        [
            'pattern' => '/\b(maiden[_\s]?name|mothers?[_\s]?maiden)\b/',
            'description' => 'Maiden name field',
            'severity' => 'critical',
            'confidence' => 0.9,
        ],
        [
            'pattern' => '/\b(full[_\s]?ssn|social[_\s]?security)\b/',
            'description' => 'Full SSN field',
            'severity' => 'critical',
            'confidence' => 0.95,
        ],
        [
            'pattern' => '/\b(drivers?[_\s]?licen[sc]e[_\s]?(number|num|no)?)\b/',
            'description' => 'Driver\'s license field',
            'severity' => 'warning',
            'confidence' => 0.85,
        ],
        [
            'pattern' => '/\b(passport[_\s]?(number|num|no)?)\b/',
            'description' => 'Passport number field',
            'severity' => 'warning',
            'confidence' => 0.85,
        ],
        [
            'pattern' => '/\b(bank[_\s]?account|account[_\s]?number)\b/',
            'description' => 'Bank account field',
            'severity' => 'warning',
            'confidence' => 0.8,
        ],
        [
            'pattern' => '/\b(biometric|fingerprint|retina|face[_\s]?scan)\b/',
            'description' => 'Biometric data field',
            'severity' => 'critical',
            'confidence' => 0.9,
        ],
        [
            'pattern' => '/\b(race|ethnicity|religion|sexual[_\s]?orientation)\b/',
            'description' => 'Sensitive demographic field',
            'severity' => 'warning',
            'confidence' => 0.8,
        ],
    ];

    /**
     * @return list<AnalyzerFinding>
     */
    #[Override]
    public function analyze(string $field, mixed $value, array $data): array
    {
        $findings = [];
        $normalizedField = strtolower($field);

        foreach (self::PII_INDICATORS as $indicator) {
            if (preg_match($indicator['pattern'], $normalizedField) === 1) {
                $findings[] = new AnalyzerFinding(
                    severity: FindingSeverity::from($indicator['severity']),
                    confidence: $indicator['confidence'],
                    field: $field,
                    pattern: $indicator['description'],
                    recommendation: sprintf(
                        'Consider whether the field "%s" (%s) is necessary for the intended purpose. Apply data minimization principles.',
                        $field,
                        $indicator['description'],
                    ),
                );
            }
        }

        return $findings;
    }
}
