<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Security;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Exception\PaymentException;

use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function strlen;

/**
 * PCI-DSS v4.0.1 compliance checks.
 *
 * Enforces that raw card numbers (PANs) are never stored, logged,
 * or transmitted in plaintext. All card data must be tokenized
 * by the payment provider's client-side SDK before reaching
 * the server.
 *
 * Key requirements enforced:
 * - Requirement 3.4: Render PAN unreadable anywhere it is stored
 * - Requirement 3.5: Do not store the PAN after authorization
 * - Requirement 4.2: Never send unprotected PANs via end-user messaging
 */
#[Internal]
final readonly class PciDssCompliance
{
    /**
     * Check if a value looks like a raw credit card number (PAN).
     *
     * If detected, this is a PCI-DSS violation; card data must
     * be tokenized client-side before reaching the server.
     */
    #[NoDiscard]
    public static function detectsPan(string $value): bool
    {
        $stripped = preg_replace('/[\s\-]/', '', $value);

        if ($stripped === null || $stripped === '') {
            return false;
        }

        // Must be 13-19 digits (standard card number lengths)
        if (strlen($stripped) < 13 || strlen($stripped) > 19) {
            return false;
        }

        if (preg_match('/^\d{13,19}$/', $stripped) !== 1) {
            return false;
        }

        // Luhn algorithm check
        return self::luhnCheck($stripped);
    }

    /**
     * Assert that no value in the array contains a raw PAN.
     *
     * Integers are screened as well as strings: `{"card_number": 4111111111111111}`
     * decodes to an int, and a string-only check would wave it through.
     *
     * The reported field is a dotted path so a violation inside a nested
     * structure names the whole route to the offending key, not just its leaf.
     * The value itself is never included.
     *
     * @param array<array-key, mixed> $data
     * @param string $path Dotted path of $data within the enclosing body
     *
     * @throws PaymentException If a PAN is detected
     */
    public static function assertNoPan(array $data, string $path = ''): void
    {
        /** @var mixed $value */
        foreach ($data as $key => $value) {
            $field = $path === '' ? (string) $key : "$path.$key";

            if (is_array($value)) {
                self::assertNoPan($value, $field);

                continue;
            }

            $candidate = match (true) {
                is_string($value) => $value,
                is_int($value) => (string) $value,
                default => null,
            };

            if ($candidate !== null && self::detectsPan($candidate)) {
                throw PaymentException::invalid(
                    "PCI-DSS violation: raw card number detected in field '$field'. "
                    . 'Card data must be tokenized client-side.',
                );
            }
        }
    }

    /**
     * Mask a card number to show only last 4 digits.
     *
     * Only used for display purposes with pre-tokenized data.
     */
    #[NoDiscard]
    public static function maskCardNumber(string $last4): string
    {
        return '****' . substr($last4, -4);
    }

    private static function luhnCheck(string $number): bool
    {
        $sum = 0;
        $alternate = false;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];

            if ($alternate) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $alternate = !$alternate;
        }

        return $sum % 10 === 0;
    }
}
