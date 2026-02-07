<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Analyzer\Policy;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Analyzer\AnalyzerFinding;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\PolicyAnalyzerInterface;

use function is_string;
use function preg_match;

/**
 * Heuristic detection of Protected Health Information (PHI) patterns in field values.
 *
 * Advisory only — not a compliance gate. This analyzer detects patterns that may
 * indicate PHI such as SSN-like numbers, date-of-birth patterns, phone numbers,
 * email addresses, and MRN-like identifiers. Results carry confidence scores
 * and should be reviewed by qualified compliance personnel.
 */
#[Api(since: '1.0.0')]
readonly class PhiDetector implements PolicyAnalyzerInterface
{
    /**
     * @return list<AnalyzerFinding>
     */
    #[Override]
    public function analyze(string $field, mixed $value, array $data): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $findings = [];

        // SSN-like pattern (XXX-XX-XXXX)
        if (preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $value) === 1) {
            $findings[] = new AnalyzerFinding(
                severity: FindingSeverity::Critical,
                confidence: 0.9,
                field: $field,
                pattern: 'SSN-like pattern (XXX-XX-XXXX)',
                recommendation: 'Verify this field does not contain a Social Security Number. Consider masking or encrypting.',
            );
        }

        // Date of birth pattern (MM/DD/YYYY or YYYY-MM-DD)
        if (preg_match('/\b(?:\d{2}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2})\b/', $value) === 1) {
            $findings[] = new AnalyzerFinding(
                severity: FindingSeverity::Warning,
                confidence: 0.6,
                field: $field,
                pattern: 'Date pattern that may indicate date of birth',
                recommendation: 'Verify this field does not contain a date of birth. Dates of birth are PHI under HIPAA.',
            );
        }

        // Phone number pattern
        if (preg_match('/\(\d{3}\)\s*\d{3}-\d{4}|\b\d{3}-\d{3}-\d{4}\b|\b\d{10}\b/', $value) === 1) {
            $findings[] = new AnalyzerFinding(
                severity: FindingSeverity::Warning,
                confidence: 0.7,
                field: $field,
                pattern: 'Phone number pattern',
                recommendation: 'Verify this field does not contain a personal phone number. Phone numbers are PHI under HIPAA.',
            );
        }

        // Email address pattern
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $value) === 1) {
            $findings[] = new AnalyzerFinding(
                severity: FindingSeverity::Warning,
                confidence: 0.7,
                field: $field,
                pattern: 'Email address pattern',
                recommendation: 'Verify this field does not contain a personal email address. Email addresses are PHI under HIPAA.',
            );
        }

        // MRN-like pattern (letters followed by digits, common MRN format)
        if (preg_match('/\b[A-Z]{2,4}\d{4,10}\b/', $value) === 1) {
            $findings[] = new AnalyzerFinding(
                severity: FindingSeverity::Info,
                confidence: 0.5,
                field: $field,
                pattern: 'MRN-like alphanumeric identifier',
                recommendation: 'Verify this field does not contain a Medical Record Number.',
            );
        }

        return $findings;
    }
}
