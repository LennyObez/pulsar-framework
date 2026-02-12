<?php

declare(strict_types=1);

namespace Pulsar\Mail\Security;

use Pulsar\Api\Internal;

use function preg_match;
use function preg_replace;

/**
 * Pattern-based PHI detection and scrubbing.
 *
 * Detects common PHI patterns (SSN, MRN, phone numbers, email addresses,
 * dates of birth) and replaces them with "[REDACTED]".
 */
#[Internal]
final readonly class PhiScrubber implements PhiScrubberInterface
{
    private const string REDACTED = '[REDACTED]';

    /**
     * Default PHI detection patterns.
     *
     * @var list<string>
     */
    private const array DEFAULT_PATTERNS = [
        '/\b\d{3}-\d{2}-\d{4}\b/',               // SSN: xxx-xx-xxxx
        '/\b\d{9}\b/',                              // SSN without dashes: xxxxxxxxx
        '/\bMRN[\s#:_-]*\d{4,12}\b/i',             // Medical Record Number
        '/\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/', // US phone numbers
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', // Email addresses
        '/\b(?:DOB|Date\s*of\s*Birth)[\s:]*\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b/i', // DOB with label
        '/\b\d{1,2}[\/-]\d{1,2}[\/-]\d{4}\b/',  // Date patterns (MM/DD/YYYY, DD-MM-YYYY)
    ];

    /** @var list<string> */
    private array $patterns;

    /**
     * @param list<string>|null $patterns Custom regex patterns; null uses defaults
     */
    public function __construct(?array $patterns = null)
    {
        $this->patterns = $patterns ?? self::DEFAULT_PATTERNS;
    }

    public function scrub(string $field, string $value): string
    {
        $result = $value;

        foreach ($this->patterns as $pattern) {
            $replaced = preg_replace($pattern, self::REDACTED, $result);
            if ($replaced !== null) {
                $result = $replaced;
            }
        }

        return $result;
    }

    public function containsPhi(string $value): bool
    {
        return array_any($this->patterns, static fn(string $pattern): bool => preg_match($pattern, $value) === 1);
    }
}
