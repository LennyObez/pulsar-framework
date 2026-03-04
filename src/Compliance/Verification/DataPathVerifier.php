<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceProfile;

use function array_diff;
use function array_values;
use function implode;
use function preg_replace;
use function sprintf;
use function str_contains;
use function strtolower;

/**
 * Verifies that routes handling sensitive data have appropriate middleware.
 *
 * Inspects route definitions (provided as structured arrays) to confirm that
 * routes tagged with data classifications have the required middleware stack
 * for the active compliance profile.
 */
#[Api(since: '1.0.0')]
final readonly class DataPathVerifier
{
    /**
     * Required middleware per data classification level.
     *
     * @var array<string, list<string>>
     */
    private const array REQUIRED_MIDDLEWARE = [
        'pci' => ['encryption', 'authentication', 'audit', 'csrf'],
        'phi' => ['encryption', 'authentication', 'audit', 'csrf'],
        'pii' => ['authentication', 'audit', 'csrf'],
        'financial' => ['encryption', 'authentication', 'audit', 'csrf'],
        'sensitive' => ['authentication', 'audit'],
    ];

    public function __construct(
        private ComplianceProfile $profile,
    ) {}

    /**
     * Verify route middleware coverage for classified data paths.
     *
     * Each route definition must include:
     * - 'path': string (route pattern)
     * - 'classification': string (data classification: pci, phi, pii, financial, sensitive)
     * - 'middleware': list<string> (middleware names applied to this route)
     *
     * @param list<array{path: string, classification: string, middleware: list<string>}> $routes
     *
     * @return list<CheckResult>
     */
    public function verify(array $routes): array
    {
        $results = [];

        foreach ($routes as $route) {
            $results[] = $this->verifyRoute(
                $route['path'],
                $route['classification'],
                $route['middleware'],
            );
        }

        return $results;
    }

    /**
     * @param list<string> $routeMiddleware
     */
    private function verifyRoute(string $path, string $classification, array $routeMiddleware): CheckResult
    {
        $required = self::REQUIRED_MIDDLEWARE[$classification] ?? [];

        if ($required === []) {
            return CheckResult::skip(
                sprintf('datapath.%s', $this->sanitizeCheckId($path)),
                sprintf('Route %s has unknown classification "%s"; skipped.', $path, $classification),
                ComplianceCheckDomain::AccessControl,
            );
        }

        // Adjust requirements based on profile
        if (!$this->profile->encryptionInTransit && !$this->profile->encryptionAtRest) {
            $required = array_values(array_diff($required, ['encryption']));
        }

        $missing = [];

        foreach ($required as $middleware) {
            if (!$this->routeHasMiddleware($routeMiddleware, $middleware)) {
                $missing[] = $middleware;
            }
        }

        $checkId = sprintf('datapath.route.%s', $this->sanitizeCheckId($path));

        if ($missing === []) {
            return CheckResult::pass(
                $checkId,
                sprintf(
                    'Route %s (%s) has all required middleware.',
                    $path,
                    $classification,
                ),
                ComplianceCheckDomain::AccessControl,
                ['path: ' . $path, 'classification: ' . $classification],
            );
        }

        return CheckResult::fail(
            $checkId,
            sprintf(
                'Route %s is classified as %s but is missing middleware: %s',
                $path,
                $classification,
                implode(', ', $missing),
            ),
            ComplianceCheckDomain::AccessControl,
            [sprintf(
                'Add the following middleware to route %s: %s',
                $path,
                implode(', ', $missing),
            )],
        );
    }

    /**
     * Check if the route's middleware list contains a given middleware (by name fragment match).
     *
     * @param list<string> $routeMiddleware
     */
    private function routeHasMiddleware(array $routeMiddleware, string $required): bool
    {
        foreach ($routeMiddleware as $middleware) {
            $normalized = strtolower($middleware);

            if (str_contains($normalized, strtolower($required))) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeCheckId(string $path): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $path) ?? $path;
    }
}
