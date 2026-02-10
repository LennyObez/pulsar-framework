<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Identity;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function in_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Validates Employer Identification Number (EIN) format.
 *
 * Format: XX-XXXXXXX where the two-digit prefix identifies the IRS campus.
 * Valid campus prefixes are in the range 10-99.
 *
 * @see https://www.irs.gov/businesses/small-businesses-self-employed/employer-id-numbers
 */
#[Api(since: '1.0.0')]
readonly class Ein implements RuleInterface
{
    /** @var list<int> Valid IRS campus prefixes */
    private const array VALID_PREFIXES = [
        10, 12, 13, 14, 15, 16, 20, 21, 22, 23, 24, 25, 26, 27,
        30, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44,
        45, 46, 47, 48, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59,
        60, 61, 62, 63, 64, 65, 66, 67, 68, 71, 72, 73, 74, 75,
        76, 77, 80, 81, 82, 83, 84, 85, 86, 87, 88, 90, 91, 92,
        93, 94, 95, 98, 99,
    ];

    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && $this->isValidEin($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid Employer Identification Number (EIN).',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidEin(string $value): bool
    {
        if (preg_match('/^(\d{2})-(\d{7})$/', $value, $matches) !== 1) {
            return false;
        }

        $prefix = (int) $matches[1];

        return in_array($prefix, self::VALID_PREFIXES, true);
    }

    #[Override]
    public function name(): string
    {
        return 'ein';
    }
}
