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
 * Validates passport number format per ICAO 9303 machine-readable specification.
 *
 * Default pattern: 1-2 letter prefix followed by 6-9 digits.
 * Configurable per country for jurisdiction-specific patterns.
 *
 * @see https://www.icao.int/publications/pages/publication.aspx?docnum=9303
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PassportNumber implements RuleInterface
{
    private string $pattern;

    public function __construct(
        string $country = '',
        private string $message = '',
    ) {
        $this->pattern = $this->resolvePattern($country);
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
                'The %s field must be a valid passport number.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function resolvePattern(string $country): string
    {
        return match ($country) {
            'GB' => '/^\d{9}$/',
            'DE' => '/^[CFGHJKLMNPRTVWXYZ0-9]{9}$/',
            'CA' => '/^[A-Z]{2}\d{6}$/',
            default => '/^[A-Z]{1,2}\d{6,9}$/',
        };
    }

    #[Override]
    public function name(): string
    {
        return 'passport_number';
    }
}
