<?php

declare(strict_types=1);

/**
 * Routing Configuration
 *
 * Request-URL canonicalization policy for Pulsar's router.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Redirect to the canonical path
    |--------------------------------------------------------------------------
    |
    | The router matches paths after trimming leading and trailing slashes, so
    | a single registered route also answers "/x/", "//x", and "///x///" with
    | a 200 — the same page under infinitely many spellings, which splits crawl
    | budget and link equity across duplicate URLs.
    |
    | Enable this to have the router issue a permanent redirect to the single
    | canonical spelling (repeated slashes collapsed, trailing slash dropped)
    | before matching. Safe methods (GET/HEAD) redirect with 301; other methods
    | redirect with 308 so the method and body are preserved. The root "/" is
    | always exempt, and the query string is carried over unchanged.
    |
    | OFF by default: leaving it off preserves the historical forgiving
    | behaviour, so turning it on is an opt-in, backwards-compatible tightening.
    | Override with the PULSAR_ROUTING_REDIRECT_TO_CANONICAL_PATH environment
    | variable ("true"/"1" to enable).
    |
    */
    'redirect_to_canonical_path' => false,
];
