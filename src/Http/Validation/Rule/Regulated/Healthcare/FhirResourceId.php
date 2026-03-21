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
 * Validates FHIR R4 resource ID format.
 *
 * FHIR resource IDs must match: [A-Za-z0-9.-]{1,64}
 *
 * @see https://www.hl7.org/fhir/datatypes.html#id
 */
#[Api(since: '1.0.0')]
final readonly class FhirResourceId implements RuleInterface
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

        if (is_string($value) && preg_match('/^[A-Za-z0-9.-]{1,64}$/', $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid FHIR resource ID (1-64 alphanumeric, dot, or dash characters).',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'fhir_resource_id';
    }
}
