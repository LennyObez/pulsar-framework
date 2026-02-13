<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Http\Message\Response;

use function array_map;
use function htmlspecialchars;
use function is_string;
use function max;
use function min;

use const ENT_QUOTES;

/**
 * Public API controller for release version information and listing.
 *
 * Provides endpoints for retrieving the latest stable version per platform
 * and browsing the full release history with pagination.
 */
#[Internal(reason: 'Release HTTP controller — implementation detail')]
final readonly class ReleaseApiController
{
    public function __construct(
        private ReleaseService $service,
        private ReleaseRepositoryInterface $repository,
    ) {}

    /**
     * GET /api/v1/version — Get the latest stable release for a platform.
     *
     * Query params:
     * - platform: string (required, one of: android, ios, web)
     *
     * Returns 200 with release data, 404 if no stable release exists,
     * 422 if platform is invalid.
     */
    public function latestVersion(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $platformValue = is_string($params['platform'] ?? null) ? $params['platform'] : '';
        $platform = ReleasePlatform::tryFrom($platformValue);

        if ($platform === null) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => [
                    'platform' => 'Invalid platform. Must be one of: android, ios, web',
                ],
            ], 422);
        }

        $release = $this->service->getLatestVersion($platform);

        if ($release === null) {
            return Response::json([
                'error' => 'No stable release found for this platform',
            ], 404);
        }

        return Response::json([
            'data' => self::serialize($release),
        ]);
    }

    /**
     * GET /api/v1/releases — List releases with optional filters, paginated.
     *
     * Query params:
     * - page: int (default 1)
     * - per_page: int (default 20, max 100)
     * - platform: string (optional, one of: android, ios, web)
     * - include_beta: string (optional, "true" or "false")
     */
    public function listReleases(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        $platformFilter = is_string($params['platform'] ?? null)
            ? ReleasePlatform::tryFrom($params['platform'])
            : null;

        $includeBeta = null;
        $includeBetaRaw = $params['include_beta'] ?? null;

        if (is_string($includeBetaRaw)) {
            $includeBeta = $includeBetaRaw === 'true';
        }

        $result = $this->repository->findAll($page, $perPage, $platformFilter, $includeBeta);

        return Response::json([
            'data' => array_map(self::serialize(...), $result->items),
            'pagination' => $result->metaToArray(),
        ]);
    }

    /**
     * Serialize a release entity for the public API.
     *
     * @return array<string, mixed>
     */
    private static function serialize(Release $release): array
    {
        return [
            'id' => $release->id,
            'version' => htmlspecialchars($release->version, ENT_QUOTES, 'UTF-8'),
            'platform' => $release->platform->value,
            'release_date' => $release->releaseDate->format('c'),
            'release_notes' => htmlspecialchars($release->releaseNotes, ENT_QUOTES, 'UTF-8'),
            'minimum_os_version' => htmlspecialchars($release->minimumOsVersion, ENT_QUOTES, 'UTF-8'),
            'download_url' => $release->downloadUrl !== null
                ? htmlspecialchars($release->downloadUrl, ENT_QUOTES, 'UTF-8')
                : null,
            'is_beta' => $release->isBeta,
            'is_stable' => $release->isStable,
            'created_at' => $release->createdAt->format('c'),
        ];
    }
}
