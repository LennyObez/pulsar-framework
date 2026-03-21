<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Legal;

use InvalidArgumentException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * Validates attorney bar number format.
 *
 * Default pattern: state prefix (2 uppercase letters) followed by digits.
 * Configurable via custom pattern for jurisdiction-specific formats.
 *
 * @see https://www.americanbar.org/
 */
#[Api(since: '1.0.0')]
final readonly class BarNumber implements RuleInterface
{
    private const int MAX_PATTERN_LENGTH = 500;

    public function __construct(
        private string $pattern = '',
        private string $message = '',
    ) {
        if ($this->pattern !== '') {
            if (strlen($this->pattern) > self::MAX_PATTERN_LENGTH) {
                throw new InvalidArgumentException('Bar number pattern exceeds maximum length of 500 characters.');
            }

            if (@preg_match($this->pattern, '') === false) {
                throw new InvalidArgumentException('Bar number pattern is not a valid regular expression.');
            }
        }
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        $regex = $this->pattern !== ''
            ? $this->pattern
            : '/^[A-Z]{2}\d{4,8}$/';

        if (is_string($value) && preg_match($regex, $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid bar number.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'bar_number';
    }
}
