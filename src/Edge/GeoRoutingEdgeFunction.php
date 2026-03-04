<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use Override;
use Pulsar\Api\Api;

/**
 * Edge function for geo-based routing.
 *
 * Redirects users to localized content based on their country code.
 * Useful for serving localized marketing pages, compliance-restricted
 * content, or regional pricing pages.
 */
#[Api(since: '1.0.0')]
final readonly class GeoRoutingEdgeFunction implements EdgeFunctionInterface
{
    /**
     * @param array<string, string> $countryRedirects Country code → URL mapping
     */
    public function __construct(
        private array $countryRedirects,
        private ?string $defaultRedirect = null,
    ) {}

    #[Override]
    public function handle(EdgeRequest $request): ?EdgeResponse
    {
        $country = $request->country();

        if ($country === null) {
            return $this->defaultRedirect !== null
                ? EdgeResponse::redirect($this->defaultRedirect)
                : null;
        }

        $redirect = $this->countryRedirects[strtoupper($country)] ?? null;

        if ($redirect !== null) {
            return EdgeResponse::redirect($redirect);
        }

        return $this->defaultRedirect !== null
            ? EdgeResponse::redirect($this->defaultRedirect)
            : null;
    }

    #[Override]
    public function name(): string
    {
        return 'geo-routing';
    }
}
