<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use Override;
use Pulsar\Api\Api;

use function array_keys;
use function count;
use function crc32;

/**
 * Edge function for A/B testing.
 *
 * Assigns users to experiment variants based on a cookie. If no
 * cookie exists, assigns deterministically from the IP address.
 * Redirects to the variant URL without a round-trip to origin.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AbTestEdgeFunction implements EdgeFunctionInterface
{
    /**
     * @param string $experimentName Cookie name for variant assignment
     * @param array<string, string> $variants Variant name → URL mapping
     */
    public function __construct(
        private string $experimentName,
        private array $variants,
        private string $cookieName = 'px_ab',
    ) {}

    #[Override]
    public function handle(EdgeRequest $request): ?EdgeResponse
    {
        if ($this->variants === []) {
            return null;
        }

        $variantNames = array_keys($this->variants);

        // Check if user already has a variant assignment
        $cookie = $request->cookie($this->cookieName . '_' . $this->experimentName);

        if ($cookie !== null && isset($this->variants[$cookie])) {
            $url = $this->variants[$cookie];

            if ($request->path !== $url && $request->url !== $url) {
                return EdgeResponse::redirect($url);
            }

            return null;
        }

        // Assign variant deterministically from IP
        $index = abs(crc32($request->ip . $this->experimentName)) % count($variantNames);
        $assignedVariant = $variantNames[$index];
        $url = $this->variants[$assignedVariant];

        $response = EdgeResponse::redirect($url)
            ->withCookie($this->cookieName . '_' . $this->experimentName, $assignedVariant);

        return $response;
    }

    #[Override]
    public function name(): string
    {
        return 'ab-test-' . $this->experimentName;
    }
}
