<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Api;

/**
 * Value object describing driver capabilities.
 *
 * Used by CacheManager to validate pool configurations
 * (e.g., strict tags require atomic increment support).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CacheDriverCapabilities
{
    public function __construct(
        public bool $supportsTagsStrict = false,
        public bool $supportsLocksFencing = false,
        public bool $supportsBinary = false,
        public bool $supportsAtomicIncrement = false,
    ) {}
}
