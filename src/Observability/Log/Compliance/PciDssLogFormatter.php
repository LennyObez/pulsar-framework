<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Compliance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Log\LogEntry;

use function in_array;
use function is_array;
use function is_string;
use function preg_replace_callback;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Masks PCI DSS sensitive data (PAN, CVV, expiry) at source.
 *
 * PAN numbers are detected via regex pattern matching and masked to show
 * only the last 4 digits. CVV values are fully masked. This masking is
 * irreversible — original data cannot be recovered from logs.
 *
 * Supports controls for PCI DSS Requirement 3.4 (render PAN unreadable).
 */
#[Internal(reason: 'Compliance formatter implementation detail')]
final class PciDssLogFormatter implements ComplianceLogFormatter
{
    /**
     * Pattern to detect potential PAN numbers (13-19 digit sequences,
     * optionally separated by spaces or dashes).
     */
    private const string PAN_PATTERN = '/\b(?:\d[ -]*?){13,19}\b/';

    /**
     * Context keys that are always fully masked when present.
     *
     * @var list<string>
     */
    private const array CVV_KEYS = ['cvv', 'cvv2', 'cvc', 'cvc2', 'security_code'];

    /**
     * Context keys that are always fully masked when present.
     *
     * @var list<string>
     */
    private const array EXPIRY_KEYS = ['expiry', 'expiry_date', 'exp_date', 'card_expiry', 'exp_month', 'exp_year'];

    #[Override]
    public function format(LogEntry $entry): LogEntry
    {
        $maskedContext = $this->maskContext($entry->context);
        $maskedMessage = $this->maskPanInString($entry->message);

        return new LogEntry(
            level: $entry->level,
            message: $maskedMessage,
            context: $maskedContext,
            channel: $entry->channel,
            timestamp: $entry->timestamp,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function maskContext(array $context): array
    {
        $masked = [];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, self::CVV_KEYS, true)) {
                $masked[$key] = '***';
            } elseif (in_array($lowerKey, self::EXPIRY_KEYS, true)) {
                $masked[$key] = '**/**';
            } elseif (is_string($value)) {
                $masked[$key] = $this->maskPanInString($value);
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $masked[$key] = $this->maskContext($value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    private function maskPanInString(string $value): string
    {
        /** @var string */
        return preg_replace_callback(self::PAN_PATTERN, function (array $matches): string {
            $digits = preg_replace('/[^0-9]/', '', $matches[0]) ?? '';

            $digitCount = strlen($digits);
            if ($digitCount < 13 || $digitCount > 19) {
                return $matches[0];
            }

            if (!$this->passesLuhn($digits)) {
                return $matches[0];
            }

            $lastFour = substr($digits, -4);

            return str_repeat('*', $digitCount - 4) . $lastFour;
        }, $value) ?? $value;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $length = strlen($digits);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$i];

            if ($i % 2 === $parity) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
