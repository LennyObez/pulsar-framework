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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function index(): Response
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawDomain */
        $rawDomain = $body['domain'] ?? null;
        $domain = is_string($rawDomain) ? $rawDomain : '';
        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) ? $rawName : '';
        /** @var mixed $rawTimezone */
        $rawTimezone = $body['timezone'] ?? null;
        $timezone = is_string($rawTimezone) ? $rawTimezone : 'UTC';
        /** @var mixed $rawSettings */
        $rawSettings = $body['settings'] ?? null;
        /** @var array<string, mixed> $settings */
        $settings = is_array($rawSettings) ? $rawSettings : [];

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function show(string $id): Response
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function update(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawDomain */
        $rawDomain = $body['domain'] ?? null;
        $domain = is_string($rawDomain) ? $rawDomain : '';
        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) ? $rawName : '';
        /** @var mixed $rawTimezone */
        $rawTimezone = $body['timezone'] ?? null;
        $timezone = is_string($rawTimezone) ? $rawTimezone : 'UTC';
        /** @var mixed $rawSettings */
        $rawSettings = $body['settings'] ?? null;
        /** @var array<string, mixed> $settings */
        $settings = is_array($rawSettings) ? $rawSettings : [];

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function delete(string $id): Response
    {
        try {
            $this->siteService->delete($id);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }
}
