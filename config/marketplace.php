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
    // A listing's tier is what the registry says about it, not a verification result.
    'minimum_trust_tier' => 'community',

    // Extension signatures are verified NOWHERE in this framework: there is no
    // publisher key, no trust store and no verifier on any install or load path.
    // This shipped `true` while MarketplaceConfig::fromArray() rejects any value
    // but false — so the file both claimed a control that does not exist and could
    // not be loaded by its own DTO. It stays false, and explicit, until a verifier
    // ships; MarketplaceConfig throws rather than let an operator re-enable it and
    // believe something is being checked.
    'verify_signatures' => false,

    // Registry cache lifetime in seconds
    'cache_lifetime' => 3600,
];
