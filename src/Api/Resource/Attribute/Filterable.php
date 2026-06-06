<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a field as filterable in API queries.
 *
 * Fields with this attribute can be used in `?filter[field]=value` query parameters.
 * The allowed operators are restricted per field to prevent injection.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Filterable
{
    /**
     * @param list<string> $operators Allowed filter operators (e.g., ['eq', 'gt', 'lt', 'in'])
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public array $operators = ['eq'],
    ) {}
}
