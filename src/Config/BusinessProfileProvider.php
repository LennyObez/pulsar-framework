<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Override;
use Pulsar\Api\Internal;

/**
 * Default business profile provider.
 *
 * Reads the business profile from the ConfigRepository. If the profile is not
 * registered (e.g., no config/business.php exists), returns an empty default.
 */
#[Internal(reason: 'Use BusinessProfileProviderInterface for public API')]
final readonly class BusinessProfileProvider implements BusinessProfileProviderInterface
{
    public function __construct(
        private ConfigRepository $configRepository,
    ) {}

    #[Override]
    public function getProfile(): BusinessProfileConfig
    {
        if ($this->configRepository->has(BusinessProfileConfig::class)) {
            return $this->configRepository->get(BusinessProfileConfig::class);
        }

        return new BusinessProfileConfig();
    }

    #[Override]
    public function getSellerParty(): array
    {
        return $this->getProfile()->sellerParty();
    }
}
