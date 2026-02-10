<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Redaction;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

use function is_array;
use function is_string;
use function preg_replace;

/**
 * Default redaction policy extending the base sensitive data scrubber.
 *
 * Adds Studio-specific patterns: DSN strings, Bearer tokens,
 * base64 secrets in SQL comments, connection strings, and URLs
 * with embedded credentials.
 */
#[Internal]
final class DefaultRedactionPolicy implements RedactionPolicyInterface
{
    private const string REDACTED = '[REDACTED]';

    private readonly SensitiveDataScrubber $scrubber;

    /**
     * @param list<string> $additionalPatterns Additional regex patterns to redact
     */
    public function __construct(
        array $additionalPatterns = [],
    ) {
        $this->scrubber = new SensitiveDataScrubber();
        $this->additionalPatterns = $additionalPatterns;
    }

    /** @var list<string> */
    private array $additionalPatterns;

    /** @var list<string> Built-in regex patterns for value redaction */
    private const array VALUE_PATTERNS = [
        // DSN strings: host=...;password=...
        '/(?:password|pwd)\s*=\s*[^\s;]+/i',
        // Connection strings with credentials: mysql://user:pass@host
        '#://[^:]+:[^@]+@#',
        // Bearer tokens
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
        // Base64-encoded secrets (long base64 strings, min 32 chars)
        '/[A-Za-z0-9+\/]{32,}={0,2}/',
        // AWS-style keys
        '/(?:AKIA|ASIA)[A-Z0-9]{16}/',
        // Generic API key patterns
        '/(?:api[_-]?key|apikey)\s*[=:]\s*[^\s,;]+/i',
    ];

    #[Override]
    public function redact(array $payload): array
    {
        // First pass: use the base scrubber for key-based redaction
        $payload = $this->scrubber->scrub($payload);

        // Second pass: pattern-based value redaction on string values
        return $this->redactValues($payload);
    }

    /**
     * Recursively apply regex patterns to string values.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redactValues(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->redactString($value);
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result[$key] = $this->redactValues($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Apply all regex patterns to a string value.
     */
    private function redactString(string $value): string
    {
        $patterns = [...self::VALUE_PATTERNS, ...$this->additionalPatterns];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, self::REDACTED, $value) ?? $value;
        }

        return $value;
    }
}
