<?php

declare(strict_types=1);

/**
 * Extension marketplace configuration.
 *
 * @see \Pulsar\Marketplace\MarketplaceConfig
 */
return [
    // Marketplace registry API URL
    'registry_url' => 'https://marketplace.pulsarphp.com/api/v1',

    // Auto-update installed extensions
    'auto_update' => false,

    // Minimum trust tier for installation: 'core', 'verified', 'community', 'untrusted'
    'minimum_trust_tier' => 'community',

    // Verify extension signatures before installation
    'verify_signatures' => true,

    // Registry cache lifetime in seconds
    'cache_lifetime' => 3600,
];
