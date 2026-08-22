<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\MobileVerifierInterface;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Domain\MobileVerificationResult;
use Pulsar\Extension\Payments\Internal\Mobile\AppStoreVerifier;
use Pulsar\Extension\Payments\Internal\Mobile\GooglePlayVerifier;

/**
 * Composite mobile gateway that delegates to platform-specific verifiers.
 *
 * Routes Apple requests to AppStoreVerifier and Google requests
 * to GooglePlayVerifier.
 */
#[Internal]
final readonly class MobileGateway implements MobileVerifierInterface
{
    public function __construct(
        private GooglePlayVerifier $googleVerifier,
        private AppStoreVerifier $appleVerifier,
    ) {}

    #[Override]
    public function verify(MobileStore $store, string $purchaseToken): MobileVerificationResult
    {
        return match ($store) {
            MobileStore::Google => $this->googleVerifier->verify($store, $purchaseToken),
            MobileStore::Apple => $this->appleVerifier->verify($store, $purchaseToken),
        };
    }
}
