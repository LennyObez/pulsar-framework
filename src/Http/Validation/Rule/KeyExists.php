<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_key_exists;
use function array_values;
use function is_array;
use function sprintf;

/**
 * Specified keys must exist in the array value. Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class KeyExists implements RuleInterface
{
    /** @var list<string> */
    private array $keys;

    public function __construct(
        string ...$keys,
    ) {
        $this->keys = array_values($keys);
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return new Violation(
                field: $field,
                message: sprintf('The %s field must be an array.', $field),
                rule: $this->name(),
            );
        }

        foreach ($this->keys as $key) {
            if (!array_key_exists($key, $value)) {
                return new Violation(
                    field: $field,
                    message: sprintf(
                        'The %s field must contain the key: %s.',
                        $field,
                        $key,
                    ),
                    rule: $this->name(),
                );
            }
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'key_exists';
    }
}
