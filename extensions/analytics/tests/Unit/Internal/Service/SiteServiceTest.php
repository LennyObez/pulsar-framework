<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Internal\Service\SiteService;

final class SiteServiceTest extends TestCase
{
    #[Test]
    public function createNormalizesDomainAndName(): void
    {
        $repo = $this->createMock(SiteRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Site $site): bool {
                return $site->domain === 'example.com'
                    && $site->name === 'Example'
                    && str_starts_with($site->trackingId, 'plsr_')
                    && $site->timezone === 'UTC';
            }));

        $service = new SiteService($repo);
        $site = $service->create('  Example.COM  ', '  Example  ');

        self::assertSame('example.com', $site->domain);
        self::assertSame('Example', $site->name);
        self::assertStringStartsWith('plsr_', $site->trackingId);
        self::assertSame('UTC', $site->timezone);
    }

    #[Test]
    public function createWithCustomTimezoneAndSettings(): void
    {
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $service = new SiteService($repo);

        $site = $service->create('test.com', 'Test', 'Europe/Paris', ['theme' => 'dark']);

        self::assertSame('Europe/Paris', $site->timezone);
        self::assertSame(['theme' => 'dark'], $site->settings);
    }

    #[Test]
    public function updateThrowsWhenSiteNotFound(): void
    {
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = new SiteService($repo);

        $this->expectException(AnalyticsException::class);
        $this->expectExceptionMessageIsOrContains('Site with ID "nonexistent" not found');

        $service->update('nonexistent', 'example.com', 'Example', 'UTC');
    }

    #[Test]
    public function updatePreservesTrackingIdAndCreatedAt(): void
    {
        $existing = new Site(
            id: 'site-1',
            domain: 'old.com',
            name: 'Old',
            trackingId: 'plsr_original',
        );

        $repo = $this->createMock(SiteRepositoryInterface::class);
        $repo->method('findById')->willReturn($existing);
        $repo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Site $site) use ($existing): bool {
                return $site->trackingId === 'plsr_original'
                    && $site->createdAt === $existing->createdAt
                    && $site->domain === 'new.com'
                    && $site->name === 'New';
            }));

        $service = new SiteService($repo);
        $updated = $service->update('site-1', '  New.COM  ', '  New  ', 'America/New_York');

        self::assertSame('new.com', $updated->domain);
        self::assertSame('New', $updated->name);
        self::assertSame('plsr_original', $updated->trackingId);
        self::assertSame('America/New_York', $updated->timezone);
    }

    #[Test]
    public function deleteThrowsWhenSiteNotFound(): void
    {
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = new SiteService($repo);

        $this->expectException(AnalyticsException::class);
        $service->delete('nonexistent');
    }

    #[Test]
    public function deleteCallsRepository(): void
    {
        $existing = new Site(
            id: 'site-1',
            domain: 'example.com',
            name: 'Example',
            trackingId: 'plsr_test',
        );

        $repo = $this->createMock(SiteRepositoryInterface::class);
        $repo->method('findById')->willReturn($existing);
        $repo->expects(self::once())->method('delete')->with('site-1');

        $service = new SiteService($repo);
        $service->delete('site-1');
    }

    #[Test]
    public function findByIdDelegatesToRepository(): void
    {
        $site = new Site(id: 's1', domain: 'a.com', name: 'A', trackingId: 'plsr_a');
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findById')->willReturn($site);

        $service = new SiteService($repo);

        self::assertSame($site, $service->findById('s1'));
    }

    #[Test]
    public function findByTrackingIdDelegatesToRepository(): void
    {
        $site = new Site(id: 's1', domain: 'a.com', name: 'A', trackingId: 'plsr_a');
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findByTrackingId')->willReturn($site);

        $service = new SiteService($repo);

        self::assertSame($site, $service->findByTrackingId('plsr_a'));
    }

    #[Test]
    public function findByDomainDelegatesToRepository(): void
    {
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findByDomain')->willReturn(null);

        $service = new SiteService($repo);

        self::assertNull($service->findByDomain('unknown.com'));
    }

    #[Test]
    public function listAllDelegatesToRepository(): void
    {
        $site = new Site(id: 's1', domain: 'a.com', name: 'A', trackingId: 'plsr_a');
        $repo = $this->createStub(SiteRepositoryInterface::class);
        $repo->method('findAll')->willReturn([$site]);

        $service = new SiteService($repo);

        self::assertCount(1, $service->listAll());
    }
}
