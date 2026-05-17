<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Validates postal/ZIP codes per country-specific patterns.
 * Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class PostalCode implements RuleInterface
{
    /** @var array<string, string> */
    private const array PATTERNS = [
        'US' => '/^\d{5}(-\d{4})?$/',
        'CA' => '/^[A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d$/',
        'GB' => '/^[A-Za-z]{1,2}\d[A-Za-z\d]?\s?\d[A-Za-z]{2}$/',
        'DE' => '/^\d{5}$/',
        'FR' => '/^\d{5}$/',
        'IT' => '/^\d{5}$/',
        'ES' => '/^\d{5}$/',
        'NL' => '/^\d{4}\s?[A-Za-z]{2}$/',
        'BE' => '/^\d{4}$/',
        'AT' => '/^\d{4}$/',
        'CH' => '/^\d{4}$/',
        'AU' => '/^\d{4}$/',
        'JP' => '/^\d{3}-?\d{4}$/',
        'BR' => '/^\d{5}-?\d{3}$/',
        'IN' => '/^\d{6}$/',
        'PL' => '/^\d{2}-?\d{3}$/',
        'SE' => '/^\d{3}\s?\d{2}$/',
        'NO' => '/^\d{4}$/',
        'DK' => '/^\d{4}$/',
        'FI' => '/^\d{5}$/',
        'PT' => '/^\d{4}-?\d{3}$/',
    ];

    public function __construct(
        private string $countryCode = 'US',
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && $this->isValid($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid %s postal code.',
                $field,
                $this->countryCode,
            ),
            rule: $this->name(),
        );
    }

    private function isValid(string $value): bool
    {
        $pattern = self::PATTERNS[$this->countryCode] ?? null;

        if ($pattern === null) {
            return false;
        }

        return preg_match($pattern, $value) === 1;
    }

    #[Override]
    public function name(): string
    {
        return 'postal_code';
    }
}
