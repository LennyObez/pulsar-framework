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
 * Validates Card Verification Value (CVV/CVC) format.
 *
 * CVV must be 3 digits (Visa/MC/Discover) or 4 digits (Amex).
 * This is a FORMAT validator only.
 *
 * WARNING: PCI DSS Requirement 3.2 prohibits storage of CVV/CVC
 * after authorization under any circumstance. Never log, cache,
 * or persist CVV values.
 *
 * @see https://www.pcisecuritystandards.org/
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Cvv implements RuleInterface
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

        if (is_string($value) && preg_match('/^\d{3,4}$/', $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid card verification value (3 or 4 digits).',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'cvv';
    }
}
