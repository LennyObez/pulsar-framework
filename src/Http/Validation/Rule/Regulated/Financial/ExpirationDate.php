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
 * Validates card expiration date format.
 *
 * Accepts MM/YY or MM/YYYY format with valid month (01-12).
 * This is a FORMAT validator only; it does NOT check whether
 * the card has expired. Expiry checking is a business-logic
 * concern that should be handled separately.
 *
 * @see https://www.pcisecuritystandards.org/
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExpirationDate implements RuleInterface
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

        if (is_string($value) && preg_match('/^(0[1-9]|1[0-2])\/(\d{2}|\d{4})$/', $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid expiration date (MM/YY or MM/YYYY).',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'expiration_date';
    }
}
