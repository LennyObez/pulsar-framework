<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Configuration for running edge functions inside the HTTP pipeline.
 *
 * Edge functions normally execute at a CDN edge on {@see EdgeRequest}; this
 * config lets an origin run the same building blocks as a global middleware
 * (an adapter maps the HTTP request to an {@see EdgeRequest}). Opt-in and
 * application-specific (which A/B experiments and geo redirects), so the
 * functions are operator-configured.
 *
 * Geo data is supplied by the upstream CDN as a request header ($geoCountryHeader,
 * e.g. Cloudflare's CF-IPCountry); without a trusted edge populating it, the
 * geo-routing function is inert.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EdgeConfig
{
    /**
     * @param list<EdgeFunctionInterface> $functions Edge functions in evaluation order
     */
    public function __construct(
        public bool $enabled = false,
        public string $geoCountryHeader = 'CF-IPCountry',
        public array $functions = [],
    ) {}

    #[NoDiscard]
    public function isUsable(): bool
    {
        return $this->enabled && $this->functions !== [];
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     geo_country_header?: string,
     *     ab_tests?: list<array<string, mixed>>,
     *     geo_redirects?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $functions = [];

        $abTests = $data['ab_tests'] ?? null;
        if (is_array($abTests)) {
            foreach ($abTests as $abTest) {
                $function = self::buildAbTest($abTest);
                if ($function !== null) {
                    $functions[] = $function;
                }
            }
        }

        $geoRedirects = $data['geo_redirects'] ?? null;
        if (is_array($geoRedirects)) {
            $geoFunction = self::buildGeoRouting($geoRedirects);
            if ($geoFunction !== null) {
                $functions[] = $geoFunction;
            }
        }

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            geoCountryHeader: Coerce::string($data['geo_country_header'] ?? null, 'CF-IPCountry'),
            functions: $functions,
        );
    }

    /**
     * @param mixed $config
     */
    private static function buildAbTest(mixed $config): ?AbTestEdgeFunction
    {
        if (!is_array($config)) {
            return null;
        }

        $experiment = Coerce::string($config['experiment_name'] ?? null);
        $variants = Coerce::mapOfString($config['variants'] ?? null);

        if ($experiment === '' || $variants === []) {
            return null;
        }

        return new AbTestEdgeFunction(
            $experiment,
            $variants,
            Coerce::string($config['cookie_name'] ?? null, 'px_ab'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function buildGeoRouting(array $config): ?GeoRoutingEdgeFunction
    {
        $countryRedirects = Coerce::mapOfString($config['country_redirects'] ?? null);
        $default = Coerce::string($config['default_redirect'] ?? null);
        $default = $default !== '' ? $default : null;

        if ($countryRedirects === [] && $default === null) {
            return null;
        }

        return new GeoRoutingEdgeFunction($countryRedirects, $default);
    }
}
