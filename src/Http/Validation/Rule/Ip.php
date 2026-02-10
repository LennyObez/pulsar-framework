<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function filter_var;
use function sprintf;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * Value must be a valid IP address. Supports v4, v6, or both. Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Ip implements RuleInterface
{
    public function __construct(
        private string $version = 'both',
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        $flag = match ($this->version) {
            'v4' => FILTER_FLAG_IPV4,
            'v6' => FILTER_FLAG_IPV6,
            default => 0,
        };

        if (filter_var($value, FILTER_VALIDATE_IP, $flag) !== false) {
            return null;
        }

        $label = match ($this->version) {
            'v4' => 'IPv4',
            'v6' => 'IPv6',
            default => 'IP',
        };

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be a valid %s address.', $field, $label),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'ip';
    }
}
