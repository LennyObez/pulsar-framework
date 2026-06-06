<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;

/**
 * Pricing API controller.
 *
 * Provides public endpoints for pricing plan queries.
 */
#[Internal]
final readonly class PricingApiController
{
    /**
     * GET /api/v1/pricing
     *
     * List available pricing plans.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function list(): Response
    {
        // Pricing plans are typically loaded from config or database.
        // This is a placeholder that returns the API structure.
        return Response::json([
            'plans' => [],
            'currency' => 'USD',
        ]);
    }
}
