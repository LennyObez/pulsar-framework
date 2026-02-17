<?php

declare(strict_types=1);

namespace Pulsar\I18n\Region;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function is_string;
use function preg_match;
use function strtoupper;

/**
 * Resolves the visitor's country from multiple signal sources.
 *
 * Resolution priority (highest to lowest):
 * 1. Explicit selection via X-Pulsar-Region header (set by JS from localStorage)
 * 2. Legacy cookie fallback (`pulsar_region`)
 * 3. GeoIP header (X-Country-Code, CF-IPCountry, X-Vercel-IP-Country)
 * 4. Accept-Language header region component
 * 5. Configured default country
 *
 * Using X-Pulsar-Region header instead of cookies avoids GDPR cookie
 * consent requirements. The JS client stores preferences in localStorage
 * and sends them via request headers on API calls.
 */
#[Api(since: '1.0.0')]
final readonly class RegionResolver
{
    /** Header name sent by JS client from localStorage (GDPR-friendly). */
    private const string HEADER_NAME = 'X-Pulsar-Region';

    /** Legacy cookie name for backward compatibility. */
    private const string COOKIE_NAME = 'pulsar_region';

    /** @var list<string> Common GeoIP headers set by CDNs and reverse proxies */
    private const array GEOIP_HEADERS = [
        'X-Country-Code',
        'CF-IPCountry',
        'X-Vercel-IP-Country',
        'X-GeoIP-Country',
    ];

    public function __construct(
        private CountryRegistry $registry,
        private string $defaultCountryCode = 'US',
    ) {}

    /**
     * Resolve the visitor's country from the request.
     */
    #[NoDiscard]
    public function resolve(ServerRequestInterface $request): Country
    {
        // 1. X-Pulsar-Region header (JS client sends from localStorage)
        $country = $this->fromHeader($request);

        if ($country !== null) {
            return $country;
        }

        // 2. Legacy cookie fallback
        $country = $this->fromCookie($request);

        if ($country !== null) {
            return $country;
        }

        // 3. GeoIP headers
        $country = $this->fromGeoIp($request);

        if ($country !== null) {
            return $country;
        }

        // 4. Accept-Language header
        $country = $this->fromAcceptLanguage($request);

        if ($country !== null) {
            return $country;
        }

        // 5. Default
        $defaultCode = $this->defaultCountryCode !== '' ? $this->defaultCountryCode : 'US';

        return $this->registry->get($defaultCode)
            ?? $this->registry->get('US')
            ?? new Country(
                code: 'US',
                name: 'United States',
                continent: Continent::NorthAmerica,
                languages: ['en'],
                currency: 'USD',
                flag: "\u{1F1FA}\u{1F1F8}",
            );
    }

    /**
     * Get the cookie name used for region persistence.
     */
    #[NoDiscard]
    public function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    private function fromHeader(ServerRequestInterface $request): ?Country
    {
        $code = $request->getHeaderLine(self::HEADER_NAME);

        if ($code === '') {
            return null;
        }

        return $this->registry->get($code);
    }

    private function fromCookie(ServerRequestInterface $request): ?Country
    {
        $cookies = $request->getCookieParams();
        $code = $cookies[self::COOKIE_NAME] ?? null;

        if (!is_string($code) || $code === '') {
            return null;
        }

        return $this->registry->get($code);
    }

    private function fromGeoIp(ServerRequestInterface $request): ?Country
    {
        foreach (self::GEOIP_HEADERS as $header) {
            $value = $request->getHeaderLine($header);

            if ($value !== '' && $this->registry->has($value)) {
                return $this->registry->get($value);
            }
        }

        return null;
    }

    private function fromAcceptLanguage(ServerRequestInterface $request): ?Country
    {
        $header = $request->getHeaderLine('Accept-Language');

        if ($header === '') {
            return null;
        }

        // Extract region from locale tags like "en-US", "fr-BE", "nl-NL"
        // Match the first tag with a region subtag
        if (preg_match('/[a-z]{2}[-_]([A-Z]{2})/i', $header, $matches) === 1) {
            $regionCode = strtoupper($matches[1]);

            return $this->registry->get($regionCode);
        }

        return null;
    }
}
