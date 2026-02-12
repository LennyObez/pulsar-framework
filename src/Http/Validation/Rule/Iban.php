<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function ctype_alpha;
use function ctype_digit;
use function is_string;
use function mb_strlen;
use function mb_strtoupper;
use function mb_substr;
use function ord;
use function preg_replace;
use function sprintf;

/**
 * Validates IBAN numbers per ISO 13616.
 * Rearranges (moves first 4 chars to end), converts letters to digits, mod 97 === 1.
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Iban implements RuleInterface
{
    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && $this->isValidIban($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid IBAN.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidIban(string $iban): bool
    {
        $cleaned = mb_strtoupper((string) preg_replace('/[\s\-]/', '', $iban));
        $length = mb_strlen($cleaned);

        if ($length < 15 || $length > 34) {
            return false;
        }

        // First two chars must be letters (country code)
        $countryCode = mb_substr($cleaned, 0, 2);

        if (!ctype_alpha($countryCode)) {
            return false;
        }

        // Check digits (positions 3-4) must be numeric
        $checkDigits = mb_substr($cleaned, 2, 2);

        if (!ctype_digit($checkDigits)) {
            return false;
        }

        // Move first 4 characters to end
        $rearranged = mb_substr($cleaned, 4) . mb_substr($cleaned, 0, 4);

        // Convert letters to numbers (A=10, B=11, ..., Z=35)
        $numeric = '';

        for ($i = 0, $len = mb_strlen($rearranged); $i < $len; $i++) {
            $char = mb_substr($rearranged, $i, 1);

            if (ctype_alpha($char)) {
                $numeric .= (string) (ord($char) - ord('A') + 10);
            } else {
                $numeric .= $char;
            }
        }

        // Mod 97 using string-based arithmetic (numbers can be very large)
        return $this->mod97($numeric) === 1;
    }

    private function mod97(string $number): int
    {
        $remainder = 0;

        for ($i = 0, $len = mb_strlen($number); $i < $len; $i++) {
            $digit = (int) mb_substr($number, $i, 1);
            $remainder = ($remainder * 10 + $digit) % 97;
        }

        return $remainder;
    }

    #[Override]
    public function name(): string
    {
        return 'iban';
    }
}
