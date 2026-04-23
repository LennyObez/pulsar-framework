<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Compliance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Security\Crypto\Hmac;

use function is_string;
use function strtolower;
use function substr;

/**
 * Pseudonymizes personal data fields in log entries for GDPR compliance.
 *
 * Replaces configured field values with keyed HMAC-based pseudonyms (truncated
 * to 16 hex characters). Uses BLAKE2b keyed hashing via the Hmac class to
 * prevent brute-force re-identification of low-entropy identifiers.
 *
 * Supports controls for GDPR Article 4(5) pseudonymization requirements.
 */
#[Internal(reason: 'Compliance formatter implementation detail')]
final class GdprLogFormatter implements ComplianceLogFormatter
{
    /**
     * @var list<string>
     */
    private readonly array $fieldsToMask;

    private const array DEFAULT_FIELDS = [
        'user_id',
        'email',
        'subject_id',
        'name',
        'ip_address',
    ];

    /**
     * @param string      $hmacKey HMAC key for keyed pseudonymization (min 16 bytes)
     * @param list<string> $fields  Field names to pseudonymize (case-insensitive matching)
     */
    public function __construct(
        private readonly string $hmacKey,
        array $fields = self::DEFAULT_FIELDS,
    ) {
        $this->fieldsToMask = $fields;
    }

    #[Override]
    public function format(LogEntry $entry): LogEntry
    {
        $pseudonymizedContext = $this->pseudonymizeContext($entry->context);

        return new LogEntry(
            level: $entry->level,
            message: $entry->message,
            context: $pseudonymizedContext,
            channel: $entry->channel,
            timestamp: $entry->timestamp,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function pseudonymizeContext(array $context): array
    {
        /** @var array<string, mixed> $result */
        $result = [];

        /** @var mixed $value */
        foreach ($context as $key => $value) {
            if ($this->shouldPseudonymize($key) && is_string($value)) {
                $result[$key] = $this->pseudonymize($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function shouldPseudonymize(string $key): bool
    {
        $lowerKey = strtolower($key);

        return array_any($this->fieldsToMask, static fn(string $field): bool => strtolower($field) === $lowerKey);
    }

    private function pseudonymize(string $value): string
    {
        return 'pseudonym_' . substr(Hmac::computeHex($value, $this->hmacKey), 0, 16);
    }
}
