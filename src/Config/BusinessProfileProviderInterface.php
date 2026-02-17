<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Provider interface for accessing the business profile.
 *
 * Extensions inject this interface to retrieve the centralized company profile
 * instead of reading their own scattered config keys. The provider resolves
 * values from the core config repository with optional fallback to extension
 * settings (for backward compatibility during migration).
 */
#[Api(since: '1.0.0')]
interface BusinessProfileProviderInterface
{
    /**
     * Get the full business profile configuration.
     */
    #[NoDiscard]
    public function getProfile(): BusinessProfileConfig;

    /**
     * Get formatted seller party data for invoice generation.
     *
     * @return array{
     *     name: string,
     *     address: array{line1: string|null, line2: string|null, city: string|null, postal_code: string|null, region: string|null, country: string},
     *     vat_number: string|null,
     *     tax_id: string|null,
     *     registration_number: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     iban: string|null,
     *     bic: string|null,
     *     bank_name: string|null,
     *     peppol_id: string|null,
     *     peppol_scheme: string|null,
     * }
     */
    #[NoDiscard]
    public function getSellerParty(): array;
}
