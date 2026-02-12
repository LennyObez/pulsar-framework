<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Healthcare;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function sprintf;
use function str_split;
use function strlen;

/**
 * Validates National Provider Identifier (NPI) format.
 *
 * NPIs are 10-digit identifiers assigned to healthcare providers.
 * Validation uses the Luhn algorithm with the prefix 80840 per CMS specification.
 * This is a FORMAT validator only — it does not verify that the NPI
 * is registered with NPPES. For authoritative verification, query
 * the NPI Registry.
 *
 * @see https://www.cms.gov/Regulations-and-Guidance/Administrative-Simplification/NationalProvIdentStand
 */
#[Api(since: '1.0.0')]
readonly class Npi implements RuleInterface
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

        if (is_string($value) && $this->isValidNpi($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid National Provider Identifier (NPI).',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidNpi(string $value): bool
    {
        if (preg_match('/^\d{10}$/', $value) !== 1) {
            return false;
        }

        // Luhn check with prefix 80840 per CMS spec
        $prefixed = '80840' . $value;
        $digits = str_split($prefixed);
        $length = strlen($prefixed);
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
        return 'npi';
    }
}
