<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * A ValueSet definition describing a set of codes from one or more code systems.
 *
 * @see https://www.hl7.org/fhir/valueset.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ValueSetDefinition
{
    /**
     * @param list<ValueSetInclude> $includes Code systems and their included codes
     */
    public function __construct(
        public string $url,
        public string $name,
        public string $status = 'active',
        public array $includes = [],
    ) {}
}
