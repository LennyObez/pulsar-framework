<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Domain;

use Pulsar\Api\Api;

/**
 * Electronic signature formats supported per eIDAS standards.
 */
#[Api(since: '1.0.0')]
enum SignatureFormat: string
{
    case XAdES = 'xades';
    case PAdES = 'pades';
    case CAdES = 'cades';
    case JAdES = 'jades';
}
