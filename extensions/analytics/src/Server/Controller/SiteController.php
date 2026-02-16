<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SiteServiceInterface;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;

/**
 * Site CRUD API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class SiteController
{
    public function __construct(
        private SiteServiceInterface $siteService,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $sites = $this->siteService->listAll();

        return Response::json([
            'data' => array_map(static fn($site) => [
                'id' => $site->id,
                'domain' => $site->domain,
                'name' => $site->name,
                'tracking_id' => $site->trackingId,
                'timezone' => $site->timezone,
                'created_at' => $site->createdAt->format('c'),
            ], $sites),
        ]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $domain = is_string($body['domain'] ?? null) ? $body['domain'] : '';
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $timezone = is_string($body['timezone'] ?? null) ? $body['timezone'] : 'UTC';
        /** @var array<string, mixed> $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        if ($domain === '' || $name === '') {
            return Response::json(['error' => 'domain and name are required'], 400);
        }

        $site = $this->siteService->create($domain, $name, $timezone, $settings);

        return Response::json([
            'id' => $site->id,
            'domain' => $site->domain,
            'name' => $site->name,
            'tracking_id' => $site->trackingId,
            'timezone' => $site->timezone,
            'created_at' => $site->createdAt->format('c'),
        ], 201);
    }

    public function show(ServerRequestInterface $request, string $id): Response
    {
        $site = $this->siteService->findById($id);

        if ($site === null) {
            return Response::json(['error' => 'Site not found'], 404);
        }

        return Response::json([
            'id' => $site->id,
            'domain' => $site->domain,
            'name' => $site->name,
            'tracking_id' => $site->trackingId,
            'timezone' => $site->timezone,
            'settings' => $site->settings,
            'created_at' => $site->createdAt->format('c'),
            'updated_at' => $site->updatedAt->format('c'),
        ]);
    }

    public function update(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $domain = is_string($body['domain'] ?? null) ? $body['domain'] : '';
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $timezone = is_string($body['timezone'] ?? null) ? $body['timezone'] : 'UTC';
        /** @var array<string, mixed> $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        if ($domain === '' || $name === '') {
            return Response::json(['error' => 'domain and name are required'], 400);
        }

        try {
            $site = $this->siteService->update($id, $domain, $name, $timezone, $settings);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json([
            'id' => $site->id,
            'domain' => $site->domain,
            'name' => $site->name,
            'tracking_id' => $site->trackingId,
            'timezone' => $site->timezone,
            'created_at' => $site->createdAt->format('c'),
            'updated_at' => $site->updatedAt->format('c'),
        ]);
    }

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        try {
            $this->siteService->delete($id);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }
}
