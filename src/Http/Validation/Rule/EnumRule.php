<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use BackedEnum;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;
use TypeError;

use function is_int;
use function is_string;
use function is_subclass_of;
use function sprintf;

/**
 * Value must be a valid case of a backed enum. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class EnumRule implements RuleInterface
{
    /**
     * @param class-string<BackedEnum> $enumClass
     */
    public function __construct(
        private string $enumClass,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ((is_string($value) || is_int($value))
            && is_subclass_of($this->enumClass, BackedEnum::class)
            && $this->tryResolve($value)
        ) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid %s value.',
                $field,
                $this->enumClass,
            ),
            rule: $this->name(),
        );
    }

    private function tryResolve(string|int $value): bool
    {
        try {
            return $this->enumClass::tryFrom($value) !== null;
        } catch (TypeError) {
            return false;
        }
    }

    #[Override]
    public function name(): string
    {
        return 'enum';
    }
}
