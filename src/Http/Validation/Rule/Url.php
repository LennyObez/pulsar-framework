<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function filter_var;
use function sprintf;

use const FILTER_VALIDATE_URL;

/**
 * Value must be a valid URL via FILTER_VALIDATE_URL. Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Url implements RuleInterface
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

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be a valid URL.', $field),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'url';
    }
}
