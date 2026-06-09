<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Support\Coerce;

use function count;

/**
 * Complexity caps for API requests.
 *
 * Enforces limits on the number of fields, nesting depth, and includes
 * per request. All violations produce 400 Bad Request responses with
 * descriptive error messages identifying which limit was exceeded.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ComplexityLimits
{
    /**
     * @param int $maxFields Maximum fields per resource (default 50)
     * @param int $maxNestingDepth Maximum nesting depth for includes (default 3)
     * @param int $maxIncludes Maximum includes count per request (default 10)
     */
    public function __construct(
        public int $maxFields = 50,
        public int $maxNestingDepth = 3,
        public int $maxIncludes = 10,
    ) {}

    /**
     * Validate a field count against the limit.
     *
     * @param int $count Number of requested fields
     * @param int|null $perResourceOverride Per-resource override (null = use global)
     *
     * @throws ApiException If the limit is exceeded
     */
    public function validateFieldCount(int $count, ?int $perResourceOverride = null): void
    {
        $limit = $perResourceOverride ?? $this->maxFields;

        if ($count > $limit) {
            throw ApiException::fieldLimitExceeded($count, $limit);
        }
    }

    /**
     * Validate a nesting depth against the limit.
     *
     * @throws ApiException If the limit is exceeded
     */
    public function validateNestingDepth(int $depth): void
    {
        if ($depth > $this->maxNestingDepth) {
            throw ApiException::nestingDepthExceeded($depth, $this->maxNestingDepth);
        }
    }

    /**
     * Validate an includes count against the limit.
     *
     * @param list<string> $includes The requested includes
     *
     * @throws ApiException If the limit is exceeded
     */
    public function validateIncludes(array $includes): void
    {
        $count = count($includes);

        if ($count > $this->maxIncludes) {
            throw ApiException::includesLimitExceeded($count, $this->maxIncludes);
        }
    }

    /**
     * Build from a raw config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxFields: Coerce::int($data['max_fields'] ?? null, 50),
            maxNestingDepth: Coerce::int($data['max_nesting_depth'] ?? null, 3),
            maxIncludes: Coerce::int($data['max_includes'] ?? null, 10),
        );
    }
}
