<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Guard;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function preg_match;
use function sprintf;

/**
 * Immutable tenant identifier value object.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TenantId
{
    private const string ID_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * @throws InvalidArgumentException If the tenant ID is empty or contains invalid characters.
     */
    public function __construct(
        public string $value,
    ) {
        if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Tenant ID must be non-empty and contain only alphanumeric characters, hyphens, and underscores. Got: "%s"',
                $value,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
