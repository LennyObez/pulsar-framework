<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\TypeRuleInterface;
use Pulsar\Http\Validation\Violation;

use function in_array;
use function sprintf;

/**
 * HTTP-friendly boolean: true, false, 1, 0, "1", "0". Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class BooleanType implements TypeRuleInterface
{
    /** @var list<mixed> */
    private const array ACCEPTED = [true, false, 1, 0, '1', '0'];

    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (in_array($value, self::ACCEPTED, true)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be true or false.', $field),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'boolean';
    }
}
