<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Http\Controller\Admin;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function htmlspecialchars;
use function is_string;
use function max;
use function min;

use const ENT_QUOTES;

/**
 * Admin controller for release CRUD operations and beta signup management.
 */
#[Internal(reason: 'Release admin controller; implementation detail')]
final readonly class ReleaseController
{
    public function __construct(
        private ReleaseService $service,
        private ReleaseRepositoryInterface $releaseRepository,
        private BetaSignupRepositoryInterface $betaSignupRepository,
    ) {}

    /**
     * GET /admin/releases: List all releases with optional filters.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        /** @var mixed $rawPlatform */
        $rawPlatform = $params['platform'] ?? null;
        $platformFilter = is_string($rawPlatform)
            ? ReleasePlatform::tryFrom($rawPlatform)
            : null;

        $includeBeta = null;
        /** @var mixed $includeBetaRaw */
        $includeBetaRaw = $params['include_beta'] ?? null;

        if (is_string($includeBetaRaw)) {
            $includeBeta = $includeBetaRaw === 'true';
        }

        $result = $this->releaseRepository->findAll($page, $perPage, $platformFilter, $includeBeta);

        return Response::json([
            'data' => array_map(self::serialize(...), $result->items),
            'pagination' => $result->metaToArray(),
        ]);
    }

    /**
     * GET /admin/releases/create: Show create release form data (field metadata).
     */
    public function create(ServerRequestInterface $request): Response
    {
        return Response::json([
            'data' => [
                'platforms' => array_map(
                    static fn(ReleasePlatform $p): string => $p->value,
                    ReleasePlatform::cases(),
                ),
                'fields' => [
                    'version' => ['type' => 'string', 'required' => true, 'max_length' => 32],
                    'platform' => ['type' => 'enum', 'required' => true],
                    'release_date' => ['type' => 'datetime', 'required' => true],
                    'release_notes' => ['type' => 'text', 'required' => true, 'max_length' => 50000],
                    'minimum_os_version' => ['type' => 'string', 'required' => true, 'max_length' => 32],
                    'download_url' => ['type' => 'url', 'required' => false, 'max_length' => 512],
                    'is_beta' => ['type' => 'boolean', 'required' => false, 'default' => false],
                    'is_stable' => ['type' => 'boolean', 'required' => false, 'default' => false],
                ],
            ],
        ]);
    }

    /**
     * POST /admin/releases: Create a new release.
     */
    public function store(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawVersion */
        $rawVersion = $body['version'] ?? null;
        $version = is_string($rawVersion) ? $rawVersion : '';
        /** @var mixed $rawPlatform */
        $rawPlatform = $body['platform'] ?? null;
        $platformValue = is_string($rawPlatform) ? $rawPlatform : '';
        $platform = ReleasePlatform::tryFrom($platformValue);

        if ($platform === null) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['platform' => 'Invalid platform. Must be one of: android, ios, web'],
            ], 422);
        }

        /** @var mixed $rawReleaseDate */
        $rawReleaseDate = $body['release_date'] ?? null;
        $releaseDateStr = is_string($rawReleaseDate) ? $rawReleaseDate : '';
        $releaseDate = $releaseDateStr !== '' ? new DateTimeImmutable($releaseDateStr) : new DateTimeImmutable();

        /** @var mixed $rawReleaseNotes */
        $rawReleaseNotes = $body['release_notes'] ?? null;
        $releaseNotes = is_string($rawReleaseNotes) ? $rawReleaseNotes : '';
        /** @var mixed $rawMinOs */
        $rawMinOs = $body['minimum_os_version'] ?? null;
        $minimumOsVersion = is_string($rawMinOs) ? $rawMinOs : '';
        /** @var mixed $rawDownloadUrl */
        $rawDownloadUrl = $body['download_url'] ?? null;
        $downloadUrl = is_string($rawDownloadUrl) ? $rawDownloadUrl : null;

        /** @var mixed $rawIsBeta */
        $rawIsBeta = $body['is_beta'] ?? false;
        $isBeta = $rawIsBeta === true || $rawIsBeta === 'true' || $rawIsBeta === 1;

        /** @var mixed $rawIsStable */
        $rawIsStable = $body['is_stable'] ?? false;
        $isStable = $rawIsStable === true || $rawIsStable === 'true' || $rawIsStable === 1;

        try {
            $release = $this->service->createRelease(
                $version,
                $platform,
                $releaseDate,
                $releaseNotes,
                $minimumOsVersion,
                $downloadUrl !== '' ? $downloadUrl : null,
                $isBeta,
                $isStable,
            );

            return Response::json([
                'data' => self::serialize($release),
            ], 201);
        } catch (InvalidArgumentException $e) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * GET /admin/releases/{id}: Show a single release.
     */
    public function edit(ServerRequestInterface $request, string $id): Response
    {
        $release = $this->releaseRepository->findById($id);

        if ($release === null) {
            return Response::json(['error' => 'Release not found'], 404);
        }

        return Response::json([
            'data' => self::serialize($release),
        ]);
    }

    /**
     * PUT /admin/releases/{id}: Update a release (mark stable, etc.).
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $markStable = ($body['is_stable'] ?? false) === true
            || ($body['is_stable'] ?? false) === 'true'
            || ($body['is_stable'] ?? false) === 1;

        if ($markStable) {
            $release = $this->service->markAsStable($id);

            if ($release === null) {
                return Response::json(['error' => 'Release not found'], 404);
            }

            return Response::json([
                'data' => self::serialize($release),
            ]);
        }

        $release = $this->releaseRepository->findById($id);

        if ($release === null) {
            return Response::json(['error' => 'Release not found'], 404);
        }

        return Response::json([
            'data' => self::serialize($release),
        ]);
    }

    /**
     * GET /admin/releases/beta-signups: List all beta signups.
     */
    public function betaSignups(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        $result = $this->betaSignupRepository->findAll($page, $perPage);

        return Response::json([
            'data' => array_map(static fn($signup): array => [
                'id' => $signup->id,
                'email' => htmlspecialchars($signup->email, ENT_QUOTES, 'UTF-8'),
                'device_type' => $signup->deviceType->value,
                'camera_brands' => $signup->cameraBrands,
                'signed_up_at' => $signup->signedUpAt->format('c'),
                'invited_at' => $signup->invitedAt?->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
        ]);
    }

    /**
     * Serialize a release entity for the admin API.
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
