<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\ConsentBanner;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for the cookie consent banner UI component.
 *
 * Controls which consent categories are shown, the privacy policy URL,
 * banner position, and whether granular opt-in is required.
 */
#[Api(since: '1.0.0')]
final readonly class ConsentBannerConfig
{
    /**
     * @param bool $enabled Whether the banner is active
     * @param string $position Banner position ('bottom', 'top', 'bottom-left', 'bottom-right')
     * @param string $privacyPolicyUrl URL to the privacy policy page
     * @param list<ConsentCategory> $categories Consent categories shown in the banner
     * @param bool $granularOptIn Require per-category opt-in (GDPR) vs blanket accept
     * @param string $cookieName Name of the cookie storing consent state
     * @param int $cookieTtlDays Cookie expiration in days
     */
    public function __construct(
        public bool $enabled = true,
        public string $position = 'bottom',
        public string $privacyPolicyUrl = '/privacy',
        public array $categories = [],
        public bool $granularOptIn = true,
        public string $cookieName = 'pulsar_consent',
        public int $cookieTtlDays = 365,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $categories = [];

        $rawCategories = $data['categories'] ?? [];

        if (is_array($rawCategories)) {
            foreach ($rawCategories as $key => $catData) {
                if (!is_array($catData)) {
                    continue;
                }

                /** @var string $catKey */
                $catKey = is_string($key) ? $key : (string) $key;
                /** @var array<string, mixed> $catData */
                $categories[] = ConsentCategory::fromArray($catKey, $catData);
            }
        }

        if ($categories === []) {
            $categories = self::defaultCategories();
        }

        return new self(
            enabled: isset($data['enabled']) && is_bool($data['enabled']) ? $data['enabled'] : true,
            position: isset($data['position']) && is_string($data['position']) ? $data['position'] : 'bottom',
            privacyPolicyUrl: isset($data['privacy_policy_url']) && is_string($data['privacy_policy_url']) ? $data['privacy_policy_url'] : '/privacy',
            categories: $categories,
            granularOptIn: isset($data['granular_opt_in']) && is_bool($data['granular_opt_in']) ? $data['granular_opt_in'] : true,
            cookieName: isset($data['cookie_name']) && is_string($data['cookie_name']) ? $data['cookie_name'] : 'pulsar_consent',
            cookieTtlDays: isset($data['cookie_ttl_days']) && is_int($data['cookie_ttl_days']) ? $data['cookie_ttl_days'] : 365,
        );
    }

    /**
     * @return list<ConsentCategory>
     */
    private static function defaultCategories(): array
    {
        return [
            new ConsentCategory('necessary', 'Necessary', 'Essential cookies required for the site to function.', true, true),
            new ConsentCategory('analytics', 'Analytics', 'Cookies that help us understand how you use the site.', false, false),
            new ConsentCategory('marketing', 'Marketing', 'Cookies used for advertising and tracking.', false, false),
            new ConsentCategory('preferences', 'Preferences', 'Cookies that remember your settings and preferences.', false, false),
        ];
    }
}
