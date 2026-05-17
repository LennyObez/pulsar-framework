<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule\Regulated\Identity;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;

/**
 * Validates Social Security Number (SSN) format.
 *
 * Accepts XXX-XX-XXXX or XXXXXXXXX format.
 * Area: 001-899 (excluding 666), group: 01-99, serial: 0001-9999.
 * This is a FORMAT validator only; it does not verify that the SSN
 * has been issued or is currently assigned. For authoritative
 * verification, consult the SSA.
 *
 * @see https://www.ssa.gov/employer/stateweb.htm
 */
#[Api(since: '1.0.0')]
final readonly class Ssn implements RuleInterface
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

        if (is_string($value) && $this->isValidSsn($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid Social Security Number.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidSsn(string $value): bool
    {
        // Strip dashes for uniform processing
        $cleaned = (string) preg_replace('/-/', '', $value);

        if (preg_match('/^\d{9}$/', $cleaned) !== 1) {
            return false;
        }

        $area = (int) substr($cleaned, 0, 3);
        $group = (int) substr($cleaned, 3, 2);
        $serial = (int) substr($cleaned, 5, 4);

        // Area: 001-899, excluding 666
        if ($area < 1 || $area > 899 || $area === 666) {
            return false;
        }

        // Group: 01-99
        if ($group < 1 || $group > 99) {
            return false;
        }

        // Serial: 0001-9999
        return $serial >= 1 && $serial <= 9999;
    }

    #[Override]
    public function name(): string
    {
        return 'ssn';
    }
}
