<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Financial;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Validates SWIFT/BIC code format.
 *
 * SWIFT codes are 8 or 11 characters: [A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?
 * - 4 letters: bank code
 * - 2 letters: country code (ISO 3166-1 alpha-2)
 * - 2 alphanumeric: location code
 * - 3 alphanumeric (optional): branch code
 *
 * @see https://www.iso.org/standard/60390.html ISO 9362
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Swift implements RuleInterface
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

        if (is_string($value) && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid SWIFT/BIC code.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'swift';
    }
}
