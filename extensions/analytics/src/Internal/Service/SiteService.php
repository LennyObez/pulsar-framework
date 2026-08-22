<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteServiceInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;

/**
 * Site CRUD operations and tracking ID generation.
 */
#[Internal(reason: 'Site management; use SiteServiceInterface')]
final readonly class SiteService implements SiteServiceInterface
{
    public function __construct(
        private SiteRepositoryInterface $siteRepository,
    ) {}

    #[Override]
    public function create(string $domain, string $name, string $timezone = 'UTC', array $settings = []): Site
    {
        $now = new DateTimeImmutable();

        $site = new Site(
            id: bin2hex(random_bytes(18)),
            domain: strtolower(trim($domain)),
            name: trim($name),
            trackingId: $this->generateTrackingId(),
            timezone: $timezone,
            settings: $settings,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->siteRepository->save($site);

        return $site;
    }

    #[Override]
    public function update(string $id, string $domain, string $name, string $timezone, array $settings = []): Site
    {
        $existing = $this->siteRepository->findById($id);

        if ($existing === null) {
            throw AnalyticsException::notFound('Site', $id);
        }

        $updated = new Site(
            id: $existing->id,
            domain: strtolower(trim($domain)),
            name: trim($name),
            trackingId: $existing->trackingId,
            timezone: $timezone,
            settings: $settings,
            createdAt: $existing->createdAt,
            updatedAt: new DateTimeImmutable(),
        );

        $this->siteRepository->save($updated);

        return $updated;
    }

    #[Override]
    public function delete(string $id): void
    {
        $existing = $this->siteRepository->findById($id);

        if ($existing === null) {
            throw AnalyticsException::notFound('Site', $id);
        }

        $this->siteRepository->delete($id);
    }

    #[Override]
    public function findById(string $id): ?Site
    {
        return $this->siteRepository->findById($id);
    }

    #[Override]
    public function findByTrackingId(string $trackingId): ?Site
    {
        return $this->siteRepository->findByTrackingId($trackingId);
    }

    #[Override]
    public function findByDomain(string $domain): ?Site
    {
        return $this->siteRepository->findByDomain($domain);
    }

    #[Override]
    public function listAll(): array
    {
        return $this->siteRepository->findAll();
    }

    /**
     * Generate a unique tracking ID in the format plsr_XXXXXXXX.
     */
    private function generateTrackingId(): string
    {
        return 'plsr_' . bin2hex(random_bytes(4));
    }
}
