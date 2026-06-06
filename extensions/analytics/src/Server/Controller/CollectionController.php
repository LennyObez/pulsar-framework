<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\TrackingServiceInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Http\Message\Response;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function str_ends_with;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_HOST;

/**
 * Handles incoming analytics events from the tracker script.
 *
 * Always returns 204 No Content: never leaks information about
 * whether tracking succeeded or failed. Validates that the Referer
 * or Origin header matches the registered site domain to prevent
 * forged beacon payloads.
 */
#[Internal(reason: 'Analytics collection endpoint; public-facing')]
final readonly class CollectionController
{
    public function __construct(
        private TrackingServiceInterface $trackingService,
        private SiteRepositoryInterface $siteRepository,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function collect(ServerRequestInterface $request): Response
    {
        try {
            $body = (string) $request->getBody();
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

            if (!is_array($payload)) {
                return Response::noContent();
            }

            /** @var array<string, mixed> $payload */

            // Validate payload origin against registered site domain
            $site = $this->validateOrigin($request, $payload);

            if ($site === null) {
                return Response::noContent();
            }

            // Pass validated site via request attribute to avoid duplicate DB lookup
            $request = $request->withAttribute('analytics.site', $site);

            /** @var mixed $type */
            $type = $payload['type'] ?? '';

            match ($type) {
                'pageview' => $this->trackingService->trackPageView($request, $payload),
                'event' => $this->trackingService->trackEvent($request, $payload),
                default => null, // Silently ignore unknown types
            };
        } catch (Throwable) {
            // Never leak errors to the client
        }

        return Response::noContent();
    }

    /**
     * Validate that the request origin matches the registered site domain.
     *
     * Checks Origin header first (set by browsers on cross-origin requests),
     * then falls back to Referer. Returns the validated Site on success, or
     * null on validation failure. The caller should pass the Site via request
     * attributes to avoid a duplicate DB lookup downstream.
     *
     * @param array<string, mixed> $payload
     */
    private function validateOrigin(ServerRequestInterface $request, array $payload): ?Site
    {
        /** @var mixed $rawTrackingId */
        $rawTrackingId = $payload['site'] ?? '';
        $trackingId = is_string($rawTrackingId) ? $rawTrackingId : '';

        if ($trackingId === '') {
            return null;
        }

        $site = $this->siteRepository->findByTrackingId($trackingId);

        if ($site === null) {
            return null;
        }

        // Get origin from Origin header or Referer
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '' || $origin === 'null') {
            $origin = $request->getHeaderLine('Referer');
        }

        if ($origin === '') {
            // No origin info; reject. Legitimate browser requests always include
            // Origin (cross-origin) or Referer (same-origin). Missing both means
            // the request was sent by a non-browser tool (curl, bot, etc.).
            return null;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);

        if (!is_string($originHost)) {
            return null;
        }

        // Exact domain match or subdomain match
        $siteDomain = strtolower($site->domain);
        $originHost = strtolower($originHost);

        if ($originHost !== $siteDomain && !str_ends_with($originHost, '.' . $siteDomain)) {
            return null;
        }

        return $site;
    }
}
