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
 * Validates ABA routing transit number format.
 *
 * ABA RTNs are 9 digits with a Federal Reserve checksum:
 * (3*d1 + 7*d2 + d3 + 3*d4 + 7*d5 + d6 + 3*d7 + 7*d8 + d9) mod 10 === 0
 * This is a FORMAT validator only; it does not verify that the
 * routing number is currently active. For authoritative verification,
 * consult the Federal Reserve.
 *
 * @see https://www.aba.com/routing-number
 */
#[Api(since: '1.0.0')]
final readonly class RoutingNumber implements RuleInterface
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

        if (is_string($value) && $this->isValidRoutingNumber($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field must be a valid ABA routing number.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    private function isValidRoutingNumber(string $value): bool
    {
        if (preg_match('/^\d{9}$/', $value) !== 1) {
            return false;
        }

        $d = [];

        for ($i = 0; $i < 9; $i++) {
            $d[] = (int) $value[$i];
        }

        $checksum = (3 * $d[0]) + (7 * $d[1]) + $d[2]
            + (3 * $d[3]) + (7 * $d[4]) + $d[5]
            + (3 * $d[6]) + (7 * $d[7]) + $d[8];

        return $checksum % 10 === 0;
    }

    #[Override]
    public function name(): string
    {
        return 'routing_number';
    }
}
