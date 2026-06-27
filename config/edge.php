<?php

declare(strict_types=1);

/**
 * Edge-function configuration.
 *
 * Opt-in. When enabled with at least one function, the edge pipeline runs at the
 * front of every HTTP request: geo redirects, A/B variant assignment, and blocks
 * short-circuit the request before routing. The same functions also run as
 * standalone building blocks at a real CDN edge.
 *
 * Geo data comes from the upstream CDN as a request header (`geo_country_header`,
 * e.g. Cloudflare's CF-IPCountry); without a trusted edge populating it, geo
 * routing is inert.
 */
return [
    'enabled' => false,
    'geo_country_header' => 'CF-IPCountry',

    // A/B experiments: variant name => destination URL. A signed cookie pins the
    // visitor to a variant.
    'ab_tests' => [
        // [
        //     'experiment_name' => 'homepage',
        //     'variants' => ['control' => '/home', 'treatment' => '/home-v2'],
        //     'cookie_name' => 'px_ab',
        // ],
    ],

    // Country-code => destination URL redirects.
    'geo_redirects' => [
        // 'country_redirects' => ['DE' => '/de', 'FR' => '/fr'],
        // 'default_redirect' => null,
    ],
];
