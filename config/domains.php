<?php

declare(strict_types=1);

/**
 * Multi-domain / subdomain routing configuration.
 *
 * By default, all extensions serve on the same domain. Subdomain mapping
 * is entirely opt-in: configure the `subdomains` array to route specific
 * subdomains to specific extension scopes.
 *
 * Each extension declares its scope in `pulsar.json` via the `scope` key.
 * The middleware matches the request's subdomain against these mappings.
 */
return [
    // Primary application domain. Override with APP_DOMAIN env variable.
    'default_domain' => env('APP_DOMAIN', 'localhost'),

    // Map subdomain prefixes to extension scopes.
    // Uncomment and configure as needed:
    //
    // 'subdomains' => [
    //     'admin'   => ['cms-admin'],
    //     'forum'   => ['forum'],
    //     'api'     => ['api'],
    //     'support' => ['tickets'],
    // ],
    'subdomains' => [],

    // Allow CORS requests across sibling subdomains.
    'cors_across_subdomains' => true,

    // Session cookie domain for cross-subdomain session sharing.
    // Set to '.example.com' (with dot prefix) to share sessions across
    // all subdomains. Override with SESSION_DOMAIN env variable.
    'shared_session_domain' => env('SESSION_DOMAIN', ''),

    // URL scheme for generated subdomain URLs.
    'scheme' => 'https',
];
