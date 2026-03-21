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

/**
 * Validates HL7 v2 date/time format.
 *
 * Format: YYYY[MM[DD[HH[MM[SS[.S[S[S[S]]]]]]]]] with optional timezone (+/-HHMM).
 *
 * @see https://www.hl7.org/fhir/datatypes.html#dateTime
 * @see https://hl7-definition.caristix.com/v2/HL7v2.5/DataTypes/DTM
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Hl7Date implements RuleInterface
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

        if (is_string($value) && $this->isValidHl7Date($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid HL7 date/time format.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidHl7Date(string $value): bool
    {
        // YYYY[MM[DD[HH[MM[SS[.S[S[S[S]]]]]]]]][+/-HHMM]
        $pattern = '/^'
            . '(\d{4})'                   // YYYY (required)
            . '(?:(0[1-9]|1[0-2])'        // MM (optional)
            . '(?:(0[1-9]|[12]\d|3[01])'  // DD (optional)
            . '(?:([01]\d|2[0-3])'        // HH (optional)
            . '(?:([0-5]\d)'              // MM (optional)
            . '(?:([0-5]\d)'              // SS (optional)
            . '(?:\.(\d{1,4}))?'          // .SSSS fractional (optional)
            . ')?)?)?)?)?'
            . '([+-]\d{4})?'              // timezone (optional)
            . '$/';

        return preg_match($pattern, $value) === 1;
    }

    #[Override]
    public function name(): string
    {
        return 'hl7_date';
    }
}
