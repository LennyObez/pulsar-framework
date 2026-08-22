<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Domain\MobileVerificationResult;

/**
 * Mobile in-app purchase verification contract.
 *
 * Implementations communicate with Google Play Developer API or
 * App Store Server API to validate subscription state.
 * @api
 */
#[Api(since: '1.0.0')]
interface MobileVerifierInterface
{
    /**
     * Verify a purchase token with the given store.
     */
    public function verify(MobileStore $store, string $purchaseToken): MobileVerificationResult;
}
