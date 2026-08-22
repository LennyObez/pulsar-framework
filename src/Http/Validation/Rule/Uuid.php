<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Value must be a valid UUID (versions 1-5). Skips null values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Uuid implements RuleInterface
{
    // \A and \z (not ^...$) so a trailing newline cannot slip through: $ matches
    // before a final \n, which would let "…000000000000\n" validate and risk
    // log/line injection, DB mismatch, or header smuggling downstream.
    private const string PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match(self::PATTERN, $value) === 1) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be a valid UUID.', $field),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'uuid';
    }
}
