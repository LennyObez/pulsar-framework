<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\ConsentBanner;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Configuration for the cookie consent banner UI component.
 *
 * Controls which consent categories are shown, the privacy policy URL,
 * banner position, and whether granular opt-in is required.
 * @api
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
     * @param array{
     *     enabled?: bool,
     *     position?: string,
     *     privacy_policy_url?: string,
     *     categories?: array<array-key, array<string, mixed>>,
     *     granular_opt_in?: bool,
     *     cookie_name?: string,
     *     cookie_ttl_days?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $categories = [];

        foreach ($data['categories'] ?? [] as $key => $catData) {
            $categories[] = ConsentCategory::fromArray(
                is_string($key) ? $key : (string) $key,
                $catData,
            );
        }

        return new self(
            enabled: $data['enabled'] ?? true,
            position: $data['position'] ?? 'bottom',
            privacyPolicyUrl: $data['privacy_policy_url'] ?? '/privacy',
            categories: $categories === [] ? self::defaultCategories() : $categories,
            granularOptIn: $data['granular_opt_in'] ?? true,
            cookieName: $data['cookie_name'] ?? 'pulsar_consent',
            cookieTtlDays: $data['cookie_ttl_days'] ?? 365,
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
