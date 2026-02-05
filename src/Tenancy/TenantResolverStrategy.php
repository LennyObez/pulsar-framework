<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

use Pulsar\Api\Api;

/**
 * Strategy for resolving the current tenant from an HTTP request.
 */
#[Api]
enum TenantResolverStrategy: string
{
    case Header = 'header';
    case Subdomain = 'subdomain';
    case Path = 'path';
}
