<?php

declare(strict_types=1);

namespace Pulsar\Http\Controller\Api;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\I18n\Region\CountryRegistry;

/**
 * Serves the country registry as JSON for the client-side region selector.
 *
 * Endpoint: GET /api/i18n/regions.json
 *
 * Returns countries grouped by continent, sorted by display order.
 * Cacheable for extended periods as country data changes infrequently.
 */
#[Internal]
final readonly class RegionApiController
{
    public function __construct(
        private CountryRegistry $registry,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $data = $this->registry->toArray();

        $response = Response::json(data: $data, status: 200);

        return $response
            ->withHeader('Cache-Control', 'public, max-age=86400, stale-while-revalidate=3600')
            ->withHeader('Vary', 'Accept-Encoding');
    }
}
