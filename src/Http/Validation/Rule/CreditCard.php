<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function mb_strlen;
use function preg_replace;
use function sprintf;
use function str_split;

/**
 * Validates credit card numbers using the Luhn algorithm.
 * Strips spaces and dashes, then validates 13-19 digits with Luhn checksum.
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class CreditCard implements RuleInterface
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

        if (is_string($value) && $this->isValidLuhn($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid credit card number.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidLuhn(string $number): bool
    {
        $cleaned = (string) preg_replace('/[\s-]/', '', $number);
        $length = mb_strlen($cleaned);

        if ($length < 13 || $length > 19) {
            return false;
        }

        if ((string) preg_replace('/\D/', '', $cleaned) !== $cleaned) {
            return false;
        }

        $digits = str_split($cleaned);
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
        return 'credit_card';
    }
}
