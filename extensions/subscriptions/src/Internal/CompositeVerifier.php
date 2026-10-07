<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\VerificationResult;

/**
 * Delegates verification to the appropriate store-specific verifier.
 *
 * Routes Google requests to GooglePlayVerifier and Apple requests
 * to AppStoreVerifier. Returns an invalid result for unknown stores.
 */
#[Internal(reason: 'Routing verifier; use SubscriptionVerifierInterface')]
final readonly class CompositeVerifier implements SubscriptionVerifierInterface
{
    public function __construct(
        private GooglePlayVerifier $googleVerifier,
        private AppStoreVerifier $appleVerifier,
    ) {}

    #[Override]
    public function verify(Store $store, string $purchaseToken): VerificationResult
    {
        return match ($store) {
            Store::Google => $this->googleVerifier->verify($store, $purchaseToken),
            Store::Apple => $this->appleVerifier->verify($store, $purchaseToken),
        };
    }
}
