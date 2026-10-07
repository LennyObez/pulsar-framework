<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

/**
 * FHIR specification versions supported by this extension.
 * @api
 */
#[Api(since: '1.0.0')]
enum FhirVersion: string
{
    case R4 = '4.0.1';
    case R5 = '5.0.0';
}
