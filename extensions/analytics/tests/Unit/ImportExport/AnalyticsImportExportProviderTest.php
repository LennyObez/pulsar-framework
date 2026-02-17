<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\ImportExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\FunnelServiceInterface;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Goal;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\ImportExport\AnalyticsImportExportProvider;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ImportRequest;

use function json_encode;

#[CoversClass(AnalyticsImportExportProvider::class)]
final class AnalyticsImportExportProviderTest extends TestCase
{
    private AnalyticsImportExportProvider $provider;
    private SiteRepositoryInterface&Stub $siteRepo;
    private GoalServiceInterface&Stub $goalService;
    private FunnelServiceInterface&Stub $funnelService;

    protected function setUp(): void
    {
        $this->siteRepo = $this->createStub(SiteRepositoryInterface::class);
        $this->goalService = $this->createStub(GoalServiceInterface::class);
        $this->funnelService = $this->createStub(FunnelServiceInterface::class);

        $this->provider = new AnalyticsImportExportProvider(
            $this->siteRepo,
            $this->goalService,
            $this->funnelService,
        );
    }

    #[Test]
    public function nameReturnsAnalytics(): void
    {
        self::assertSame('analytics', $this->provider->name());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Analytics', $this->provider->label());
    }

    #[Test]
    public function supportsJsonFormat(): void
    {
        self::assertSame(['json'], $this->provider->supportedFormats());
    }

    #[Test]
    public function exportSites(): void
    {
        $site = new Site(
            id: 'site-1',
            domain: 'example.com',
            name: 'Example',
            trackingId: 'plsr_abc123',
            timezone: 'UTC',
        );

        $this->siteRepo->method('findAll')->willReturn([$site]);
        $this->goalService->method('listForSite')->willReturn([]);
        $this->funnelService->method('listForSite')->willReturn([]);

        $result = $this->provider->export(new ExportRequest());

        self::assertSame('analytics', $result->providerName);
        self::assertArrayHasKey('sites', $result->data);
        self::assertCount(1, $result->data['sites']);
        self::assertSame('example.com', $result->data['sites'][0]['domain']);
    }

    #[Test]
    public function exportGoals(): void
    {
        $site = new Site('s1', 'test.com', 'Test', 'plsr_1', 'UTC');
        $goal = new Goal('g1', 's1', 'Sign Up', GoalType::CustomEvent, 'signup');

        $this->siteRepo->method('findAll')->willReturn([$site]);
        $this->goalService->method('listForSite')->willReturn([$goal]);
        $this->funnelService->method('listForSite')->willReturn([]);

        $result = $this->provider->export(new ExportRequest(entityTypes: ['goals']));

        self::assertArrayHasKey('goals', $result->data);
        self::assertCount(1, $result->data['goals']);
        self::assertSame('Sign Up', $result->data['goals'][0]['name']);
        self::assertSame('custom_event', $result->data['goals'][0]['goal_type']);
    }

    #[Test]
    public function importSites(): void
    {
        $this->siteRepo->method('findByDomain')->willReturn(null);

        $content = json_encode([
            'sites' => [
                ['domain' => 'new-site.com', 'name' => 'New Site'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content, dryRun: true));

        self::assertSame(['sites' => 1], $result->created);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function importSkipsExistingSites(): void
    {
        $existing = new Site('s1', 'existing.com', 'Existing', 'plsr_1', 'UTC');
        $this->siteRepo->method('findByDomain')->willReturn($existing);

        $content = json_encode([
            'sites' => [
                ['domain' => 'existing.com'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertSame(['sites' => 1], $result->skipped);
        self::assertSame([], $result->created);
    }

    #[Test]
    public function importWarnsOnMissingDomain(): void
    {
        $content = json_encode([
            'sites' => [
                ['name' => 'No Domain'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing domain', $result->warnings[0]);
    }

    #[Test]
    public function importGoals(): void
    {
        $content = json_encode([
            'goals' => [
                [
                    'site_id' => 's1',
                    'name' => 'Purchase',
                    'goal_type' => 'custom_event',
                    'target_value' => 'purchase_complete',
                ],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content, dryRun: true));

        self::assertSame(['goals' => 1], $result->created);
    }

    #[Test]
    public function importWarnsOnInvalidGoalType(): void
    {
        $content = json_encode([
            'goals' => [
                [
                    'site_id' => 's1',
                    'name' => 'Bad Goal',
                    'goal_type' => 'invalid_type',
                ],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertSame(['goals' => 1], $result->skipped);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('invalid goal_type', $result->warnings[0]);
    }

    #[Test]
    public function schemaDescribesAllEntityTypes(): void
    {
        $schema = $this->provider->schema();

        self::assertArrayHasKey('sites', $schema);
        self::assertArrayHasKey('goals', $schema);
        self::assertArrayHasKey('funnels', $schema);
        self::assertArrayHasKey('domain', $schema['sites']);
        self::assertArrayHasKey('goal_type', $schema['goals']);
        self::assertArrayHasKey('steps', $schema['funnels']);
    }
}
