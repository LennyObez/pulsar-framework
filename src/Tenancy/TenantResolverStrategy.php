<?php

declare(strict_types=1);

namespace Pulsar\Tenancy;

/**
 * Strategy for resolving the current tenant from an HTTP request.
 */
enum TenantResolverStrategy: string
{
    case Header = 'header';
    case Subdomain = 'subdomain';
    case Path = 'path';
}
