<?php

declare(strict_types=1);

namespace Pulsar\Observability\Diagnostics;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;

use function hash_equals;
use function preg_match;

/**
 * Authorization guard for the framework diagnostics and metrics endpoints.
 *
 * Diagnostics dashboards and OpenMetrics scrape endpoints expose request
 * counts, latency histograms, error fingerprints, span samples, queue
 * depths, and configuration excerpts. Without a guard, anyone who can
 * reach the worker — including unauthenticated users on a misconfigured
 * load balancer — can fingerprint the application and lift sensitive
 * operational data (F8.1, F8.2). Per-route auth checks are awkward to
 * compose into the routing layer, so this guard is invoked from the
 * route handler itself.
 *
 * The guard is configured with a single Bearer token taken from an env
 * variable (so it never lives in committed config). The expected wire
 * format is `Authorization: Bearer <token>`. Comparison uses
 * `hash_equals` so the check time does not leak the prefix length on
 * miss-matches.
 *
 * Three operating modes:
 *
 * - `$expectedToken === null`: guard refuses every request
 *   (deploy-time disabled — endpoints are off entirely in production).
 * - `$expectedToken === ''`: guard refuses every request and is
 *   considered misconfigured.
 * - non-empty `$expectedToken`: requests must present the matching
 *   token via `Authorization: Bearer …`.
 */
#[Internal]
final readonly class DiagnosticsAuthGuard
{
    public function __construct(
        public ?string $expectedToken,
    ) {}

    public function isAuthorized(ServerRequestInterface $request): bool
    {
        if ($this->expectedToken === null || $this->expectedToken === '') {
            return false;
        }

        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '') {
            return false;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches) !== 1) {
            return false;
        }

        return hash_equals($this->expectedToken, $matches[1]);
    }
}
