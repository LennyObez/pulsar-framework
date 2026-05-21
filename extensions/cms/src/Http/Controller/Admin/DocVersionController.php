<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Docs\DocVersionServiceInterface;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Admin controller for managing documentation versions.
 *
 * Provides endpoints to list available doc versions and set
 * the default version displayed to visitors.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class DocVersionController
{
    public function __construct(
        private DocVersionServiceInterface $versionService,
    ) {}

    /**
     * GET /admin/cms/docs/versions: List all documentation versions.
     */
    public function index(): Response
    {
        $versions = $this->versionService->listVersions();

        return Response::json([
            'data' => $versions,
            'default' => $this->versionService->getDefaultVersion(),
        ]);
    }

    /**
     * PUT /admin/cms/docs/versions/default: Set the default documentation version.
     */
    public function setDefault(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) $request->getParsedBody();

        $slug = $body['version_slug'] ?? null;

        if (!is_string($slug) || $slug === '') {
            return Response::json(['error' => 'version_slug is required'], 400);
        }

        $this->versionService->setDefaultVersion($slug);

        return Response::json(['status' => 'ok']);
    }
}
