<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Financial;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_split;
use function strlen;

/**
 * Validates Primary Account Number (PAN) format.
 *
 * Strips spaces and dashes, validates 13-19 digits with Luhn checksum.
 * This is a FORMAT validator only — it does not verify that the card
 * number has been issued or is currently active.
 *
 * WARNING: PCI DSS Requirement 3 prohibits storage of full PAN unless
 * encrypted. Never log or persist raw PAN values. Display only the
 * last four digits (masking) per PCI DSS Requirement 3.3.
 *
 * @see https://www.iso.org/standard/66011.html ISO/IEC 7812
 * @see https://www.pcisecuritystandards.org/ PCI DSS
 */
#[Api(since: '1.0.0')]
readonly class Pan implements RuleInterface
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

        if (is_string($value) && $this->isValidPan($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid primary account number (PAN).',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidPan(string $value): bool
    {
        $cleaned = (string) preg_replace('/[\s-]/', '', $value);

        if (preg_match('/^\d{13,19}$/', $cleaned) !== 1) {
            return false;
        }

        return $this->passesLuhn($cleaned);
    }

    private function passesLuhn(string $number): bool
    {
        $digits = str_split($number);
        $length = strlen($number);
        $sum = 0;
        $alternate = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

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

    #[Override]
    public function name(): string
    {
        return 'pan';
    }
}
