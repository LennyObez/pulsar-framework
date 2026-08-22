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
 * Value must contain only alphabetic characters. Supports optional unicode mode. Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Alpha implements RuleInterface
{
    public function __construct(
        private bool $unicode = false,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        $pattern = $this->unicode ? '/\A\p{L}+\z/u' : '/\A[a-zA-Z]+\z/';

        if (is_string($value) && preg_match($pattern, $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must only contain letters.', $field),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'alpha';
    }
}
