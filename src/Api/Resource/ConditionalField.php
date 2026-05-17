<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Value object for fields conditionally included in API responses.
 *
 * Wraps a field value that is only included when the specified condition is met.
 * This allows resources to dynamically include/exclude fields based on
 * authentication state, permissions, scopes, or arbitrary runtime conditions.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConditionalField
{
    /**
     * @param mixed $value The field value to include if the condition is true
     * @param bool $include Whether to include this field in the response
     */
    public function __construct(
        public mixed $value,
        public bool $include,
    ) {}

    /**
     * Create a field that is included when the condition is true.
     */
    #[NoDiscard]
    public static function when(bool $condition, mixed $value): self
    {
        return new self(value: $value, include: $condition);
    }

    /**
     * Create a field that is included unless the condition is true.
     */
    #[NoDiscard]
    public static function unless(bool $condition, mixed $value): self
    {
        return new self(value: $value, include: !$condition);
    }
}
