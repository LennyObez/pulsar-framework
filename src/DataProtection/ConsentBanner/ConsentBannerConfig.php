<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\ConsentBanner;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;
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
        $rawCats = $data['categories'] ?? null;
        if (is_array($rawCats)) {
            foreach ($rawCats as $key => $catData) {
                if (!is_array($catData)) {
                    continue;
                }
                $categories[] = ConsentCategory::fromArray(
                    is_string($key) ? $key : (string) $key,
                    $catData,
                );
            }
        }

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null, true),
            position: Coerce::string($data['position'] ?? null, 'bottom'),
            privacyPolicyUrl: Coerce::string($data['privacy_policy_url'] ?? null, '/privacy'),
            categories: $categories === [] ? self::defaultCategories() : $categories,
            granularOptIn: Coerce::strictBool($data['granular_opt_in'] ?? null, true),
            cookieName: Coerce::string($data['cookie_name'] ?? null, 'pulsar_consent'),
            cookieTtlDays: Coerce::int($data['cookie_ttl_days'] ?? null, 365),
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
