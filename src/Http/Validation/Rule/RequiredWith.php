<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_key_exists;
use function array_values;
use function implode;
use function sprintf;

/**
 * Field is required when ANY of the specified fields are present (non-null) in data.
 * Does NOT skip null; it checks the condition and fails if required and missing.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RequiredWith implements RuleInterface
{
    /** @var list<string> */
    private array $fields;

    public function __construct(
        string ...$fields,
    ) {
        $this->fields = array_values($fields);
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        $anyPresent = false;

        foreach ($this->fields as $otherField) {
            if (array_key_exists($otherField, $data) && $data[$otherField] !== null) {
                $anyPresent = true;
                break;
            }
        }

        if (!$anyPresent) {
            return null;
        }

        $missing = $value === null || $value === '' || $value === [];

        if ($missing) {
            return new Violation(
                field: $field,
                message: sprintf(
                    'The %s field is required when %s is present.',
                    $field,
                    implode(' / ', $this->fields),
                ),
                rule: $this->name(),
            );
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'required_with';
    }
}
