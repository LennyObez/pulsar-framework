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
 * Validates Medical Record Number format.
 *
 * MRNs are alphanumeric identifiers typically 4-20 characters long,
 * following common healthcare facility patterns.
 *
 * @see https://www.hl7.org/fhir/datatypes.html#identifier
 */
#[Api(since: '1.0.0')]
readonly class Mrn implements RuleInterface
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

        if (is_string($value) && preg_match('/^[A-Za-z0-9]{4,20}$/', $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid medical record number (4-20 alphanumeric characters).',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'mrn';
    }
}
