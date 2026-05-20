<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Compliance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Security\Crypto\Hmac;

use function in_array;
use function is_string;
use function strtolower;
use function substr;

/**
 * Marks PHI access and pseudonymizes patient identifiers in log entries.
 *
 * Detects Protected Health Information (PHI) categories in log context and
 * adds a `phi_access: true` flag. Patient identifiers are pseudonymized
 * using keyed BLAKE2b HMAC to prevent brute-force re-identification of
 * low-entropy identifiers such as SSNs and patient IDs.
 *
 * Supports controls for HIPAA Privacy Rule (45 CFR 164.514) safe harbor
 * de-identification requirements.
 */
#[Internal(reason: 'Compliance formatter implementation detail')]
final class HipaaLogFormatter implements ComplianceLogFormatter
{
    /**
     * Context keys that indicate PHI categories per HIPAA Safe Harbor.
     *
     * @var list<string>
     */
    private const array PHI_KEYS = [
        'patient_id',
        'patient_name',
        'date_of_birth',
        'ssn',
        'medical_record',
        'diagnosis',
        'treatment',
        'prescription',
        'health_plan_id',
        'mrn',
    ];

    /**
     * Context keys containing patient identifiers to pseudonymize.
     *
     * @var list<string>
     */
    private const array PATIENT_ID_KEYS = [
        'patient_id',
        'patient_name',
        'ssn',
        'mrn',
        'health_plan_id',
    ];

    /**
     * @param string $hmacKey HMAC key for keyed pseudonymization (min 16 bytes)
     */
    public function __construct(
        private readonly string $hmacKey,
    ) {}

    #[Override]
    public function format(LogEntry $entry): LogEntry
    {
        $context = $entry->context;
        $phiDetected = false;

        /** @var mixed $value */
        foreach ($context as $key => $value) {
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, self::PHI_KEYS, true)) {
                $phiDetected = true;
            }

            if (in_array($lowerKey, self::PATIENT_ID_KEYS, true) && is_string($value)) {
                $context[$key] = $this->pseudonymize($value);
            }
        }

        if ($phiDetected) {
            $context['phi_access'] = true;
        }

        return new LogEntry(
            level: $entry->level,
            message: $entry->message,
            context: $context,
            channel: $entry->channel,
            timestamp: $entry->timestamp,
        );
    }

    private function pseudonymize(string $value): string
    {
        return 'patient_' . substr(Hmac::computeHex($value, $this->hmacKey), 0, 16);
    }
}
