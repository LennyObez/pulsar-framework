<?php

declare(strict_types=1);

/**
 * Business Profile Configuration
 *
 * Centralized company/organization information used across extensions
 * (invoicing, compliance, analytics branding, legal pages, etc.).
 *
 * All values can be overridden by environment variables with the BUSINESS_ prefix.
 * Sensitive values (IBAN, BIC) should be set via environment variables rather
 * than committed to version control.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Company Identity
    |--------------------------------------------------------------------------
    */
    'company_name' => '',
    'trading_name' => null,
    'legal_form' => null,
    'registration_number' => null,
    'vat_number' => null,
    'tax_id' => null,

    /*
    |--------------------------------------------------------------------------
    | Address
    |--------------------------------------------------------------------------
    */
    'address_line1' => null,
    'address_line2' => null,
    'city' => null,
    'postal_code' => null,
    'region' => null,
    'country' => 'US',

    /*
    |--------------------------------------------------------------------------
    | Contact
    |--------------------------------------------------------------------------
    */
    'phone' => null,
    'email' => null,
    'website' => null,

    /*
    |--------------------------------------------------------------------------
    | Banking (for invoice generation)
    |--------------------------------------------------------------------------
    | Sensitive: prefer BUSINESS_IBAN and BUSINESS_BIC environment variables.
    */
    'iban' => null,
    'bic' => null,
    'bank_name' => null,

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */
    'logo_path' => null,

    /*
    |--------------------------------------------------------------------------
    | E-Invoicing (Peppol)
    |--------------------------------------------------------------------------
    */
    'peppol_id' => null,
    'peppol_scheme' => null,
];
