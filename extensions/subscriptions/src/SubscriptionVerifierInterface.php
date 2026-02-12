<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use Pulsar\Api\Api;

/**
 * Verifies a purchase token against the respective store API.
 *
 * Implementations communicate with Google Play Developer API or
 * App Store Server API to validate subscription state.
 */
#[Api(since: '1.0.0')]
interface SubscriptionVerifierInterface
{
    /**
     * Verify a purchase token with the given store.
     *
     * @param Store $store The platform that issued the purchase
     * @param string $purchaseToken The raw purchase token from the client
     */
    public function verify(Store $store, string $purchaseToken): VerificationResult;
}
