<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Configures a blind index column for an encrypted property.
 *
 * Blind indexes allow equality lookups on encrypted data without
 * exposing the plaintext. The index is a keyed hash (BLAKE2b)
 * stored in a separate column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class BlindIndex
{
    /**
     * @param string $column The blind index column name in the database
     * @param int $hashLength Truncated hash length in bytes (default 32)
     */
    public function __construct(
        public string $column,
        public int $hashLength = 32,
    ) {}
}
