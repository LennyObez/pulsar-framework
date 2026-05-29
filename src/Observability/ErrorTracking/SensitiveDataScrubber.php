<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use Pulsar\Api\Api;

use function array_any;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function preg_replace;
use function preg_split;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Recursively scrubs sensitive data from arrays and headers.
 *
 * Uses case-insensitive substring matching against a configurable list
 * of sensitive field names.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SensitiveDataScrubber
{
    private const string REDACTED = '[REDACTED]';

    /** @var list<string> Default sensitive field substrings */
    private const array DEFAULT_FIELDS = [
        'password',
        'token',
        'secret',
        'api_key',
        'authorization',
        'credential',
        'credit_card',
        'ssn',
        'social_security',
        'private_key',
    ];

    /** @var list<string> Headers that are always scrubbed */
    private const array SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
    ];

    /** @var list<string> */
    private array $fields;

    /**
     * @param list<string> $customFields Additional sensitive field substrings
     */
    public function __construct(array $customFields = [])
    {
        $this->fields = array_merge(self::DEFAULT_FIELDS, $customFields);
    }

    /**
     * Recursively scrub sensitive values from an array.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function scrub(array $data): array
    {
        $result = [];

        /** @var mixed $value */
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $result = [...$result, $key => self::REDACTED];
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result = [...$result, $key => $this->scrub($value)];
            } else {
                $result = [...$result, $key => $value];
            }
        }

        return $result;
    }

    /**
     * F8.5: scrub a free-form string for credit-card numbers, JWTs,
     * long hex/base64 tokens. Used by stack-trace formatters and
     * other places where structured-key scrubbing cannot apply
     * (PHP trace `args` may contain user-supplied secrets surfaced
     * as scalars without an obvious key).
     *
     * The patterns are deliberately narrow — false positives waste
     * legitimate diagnostic value, false negatives leak credentials.
     * Order matters: longer / more-specific patterns first.
     */
    public function scrubString(string $input): string
    {
        $patterns = [
            // PAN-like: 12-19 digits with optional dash/space separators.
            // Catches `4111-1111-1111-1111`, `4111 1111 1111 1111`,
            // and the unseparated `4111111111111111`. Not Luhn-validated
            // here — false positives (long invoice numbers) are
            // acceptable in a logging context.
            '/\b(?:\d[ -]?){13,19}\b/' => self::REDACTED,
            // JWT: three base64url segments separated by dots, last segment
            // (signature) is non-empty. The leading `eyJ` is a Base64
            // encoding of `{"` which always starts a JWT header.
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/' => self::REDACTED,
            // Long hex tokens (32+ hex chars) — typical for API keys,
            // session ids, HMACs.
            '/\b[a-fA-F0-9]{32,}\b/' => self::REDACTED,
            // Base64 secrets (40+ chars). Tighter than the hex rule
            // because base64 alphabet collides with prose; keep the
            // length floor high so noun phrases are not redacted.
            '/\b[A-Za-z0-9+\/]{40,}={0,2}\b/' => self::REDACTED,
        ];

        $result = $input;

        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $result) ?? $result;
        }

        return $result;
    }

    /**
     * Scrub sensitive headers.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string|list<string>>
     */
    public function scrubHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            if ($this->isSensitiveHeader($name)) {
                $result[$name] = self::REDACTED;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * F8.6: previously this was `str_contains($lower, $field)`, which
     * over-matched on substrings: `tokenizer`, `tokens_per_second`,
     * `tokenization_settings`, `payment_token_count` all redacted on
     * `token`. The fix is word-boundary aware — split the key into
     * segments by common separators (`_`, `-`, `.`, ` `) and on
     * camelCase boundaries, then require an exact segment match. So
     * `auth_token` / `accessToken` still match `token`, while
     * `tokenizer` (a single segment) does not. Multi-word sensitive
     * entries (`credit_card`, `private_key`) are handled by also
     * checking adjacent-segment runs against the segmented field
     * patterns.
     */
    private function isSensitiveKey(string $key): bool
    {
        $segments = self::segmentKey($key);

        if ($segments === []) {
            return false;
        }

        return array_any(
            $this->fields,
            static fn(string $field): bool => self::segmentsContainField($segments, $field),
        );
    }

    /**
     * Split a key into lowercase segments by separators + camelCase
     * boundaries, then add a singular form for any plural segment.
     * `accessTokenValue` → ['access', 'token', 'value'];
     * `auth-tokens` → ['auth', 'tokens', 'token'];
     * `APIKey` → ['api', 'key'].
     *
     * The singular folding lets `auth_tokens` still match the
     * `token` field while keeping `tokenizer` (a single segment) safe.
     *
     * @return list<string>
     */
    private static function segmentKey(string $key): array
    {
        // Insert `_` before uppercase that follows lowercase / digit
        // (handles camelCase / PascalCase) and between consecutive
        // uppercase + lowercase (handles acronyms like APIKey → api_key).
        $normalised = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
        $normalised = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '_', $normalised) ?? $normalised;

        $parts = preg_split('/[_\-. ]+/', strtolower($normalised));

        if ($parts === false) {
            return [];
        }

        $segments = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $segments[] = $part;

            // Naive plural stripping: '*ies' → '*y'; '*es' / '*s' → strip
            // suffix. Lets `auth_tokens` match `token`, `passwords` match
            // `password`. We accept benign duplicates (`y` already in
            // segments) — `array_unique` deduplicates downstream.
            if (str_ends_with($part, 'ies') && strlen($part) > 3) {
                $segments[] = substr($part, 0, -3) . 'y';
            } elseif (str_ends_with($part, 'es') && strlen($part) > 2) {
                $segments[] = substr($part, 0, -2);
            } elseif (str_ends_with($part, 's') && strlen($part) > 1) {
                $segments[] = substr($part, 0, -1);
            }
        }

        return array_values(array_unique($segments));
    }

    /**
     * @param list<string> $segments
     */
    private static function segmentsContainField(array $segments, string $field): bool
    {
        $fieldSegments = self::segmentKey($field);

        if ($fieldSegments === []) {
            return false;
        }

        // Single-token field: any matching key segment qualifies.
        if (count($fieldSegments) === 1) {
            return in_array($fieldSegments[0], $segments, true);
        }

        // Multi-token field (`credit_card`, `private_key`): every part
        // of the field must be present somewhere in the key segments.
        // Order is intentionally relaxed — `card_credit` is still
        // sensitive — but every component must appear.
        foreach ($fieldSegments as $part) {
            if (!in_array($part, $segments, true)) {
                return false;
            }
        }

        return true;
    }

    private function isSensitiveHeader(string $name): bool
    {
        $lower = strtolower($name);

        if (in_array($lower, self::SENSITIVE_HEADERS, true)) {
            return true;
        }

        // Also check general sensitive fields
        return $this->isSensitiveKey($name);
    }
}
