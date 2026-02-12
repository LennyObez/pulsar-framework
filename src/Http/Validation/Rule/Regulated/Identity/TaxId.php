<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Identity;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Validates Tax ID format by jurisdiction.
 *
 * Supports jurisdiction-specific patterns for common tax identification formats.
 *
 * @see https://www.irs.gov/individuals/international-taxpayers/taxpayer-identification-numbers-tin
 */
#[Api(since: '1.0.0')]
readonly class TaxId implements RuleInterface
{
    private string $pattern;

    public function __construct(
        private string $jurisdiction = 'US',
        private string $message = '',
    ) {
        $this->pattern = $this->resolvePattern($this->jurisdiction);
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match($this->pattern, $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid tax identification number for %s.',
                $field,
                $this->jurisdiction,
            ),
            rule: $this->name(),
        );
    }

    private function resolvePattern(string $jurisdiction): string
    {
        return match ($jurisdiction) {
            // US: SSN (XXX-XX-XXXX) or EIN (XX-XXXXXXX) or ITIN (9XX-XX-XXXX)
            'US' => '/^(\d{3}-\d{2}-\d{4}|\d{2}-\d{7}|9\d{2}-\d{2}-\d{4})$/',
            // UK: National Insurance Number
            'GB' => '/^[A-CEGHJ-PR-TW-Z]{2}\d{6}[A-D]$/',
            // Canada: Social Insurance Number (XXX-XXX-XXX)
            'CA' => '/^\d{3}-\d{3}-\d{3}$/',
            // Germany: Steueridentifikationsnummer (11 digits)
            'DE' => '/^\d{11}$/',
            // France: Numero fiscal de reference (13 digits)
            'FR' => '/^\d{13}$/',
            default => '/^[A-Za-z0-9\-]{5,20}$/',
        };
    }

    #[Override]
    public function name(): string
    {
        return 'tax_id';
    }
}
