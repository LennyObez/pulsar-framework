<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use Pulsar\Api\Api;

/**
 * Types of certificates tracked by the certificate monitor.
 * @api
 */
#[Api(since: '1.0.0')]
enum CertificateType: string
{
    case Tls = 'tls';
    case Signing = 'signing';
    case FipsModule = 'fips_module';
    case ClientAuth = 'client_auth';
}
