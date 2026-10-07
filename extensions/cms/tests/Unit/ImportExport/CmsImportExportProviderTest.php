<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\ImportExport;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ImportExport\CmsImportExportProvider;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult as CmsImportResult;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ImportRequest;

#[CoversClass(CmsImportExportProvider::class)]
final class CmsImportExportProviderTest extends TestCase
{
    private CmsImportExportProvider $provider;
    private ImportExportServiceInterface&Stub $service;

    protected function setUp(): void
    {
        $this->service = $this->createStub(ImportExportServiceInterface::class);
        $this->provider = new CmsImportExportProvider($this->service);
    }

    #[Test]
    public function nameReturnsCms(): void
    {
        self::assertSame('cms', $this->provider->name());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Content Management', $this->provider->label());
    }

    #[Test]
    public function supportsJsonFormat(): void
    {
        self::assertSame(['json'], $this->provider->supportedFormats());
    }

    #[Test]
    public function exportDelegatesToCmsService(): void
    {
        $bundle = new ExportBundle(
            data: ['content' => [['id' => '1', 'title' => 'Test']]],
            evidenceHash: 'abc123',
            createdAt: new DateTimeImmutable('2026-01-01'),
            scope: ['content'],
            piiIncluded: false,
        );

        $this->service->method('exportBundle')->willReturn($bundle);

        $result = $this->provider->export(new ExportRequest());

        self::assertSame('cms', $result->providerName);
        self::assertSame('json', $result->format);
        self::assertSame('abc123', $result->evidenceHash);
        self::assertArrayHasKey('content', $result->data);
    }

    #[Test]
    public function exportFiltersEntityTypes(): void
    {
        $bundle = new ExportBundle(
            data: ['content' => []],
            evidenceHash: 'h',
            createdAt: new DateTimeImmutable(),
            scope: ['content'],
            piiIncluded: false,
        );

        $this->service->method('exportBundle')->willReturn($bundle);

        $result = $this->provider->export(new ExportRequest(entityTypes: ['content']));

        self::assertSame(['content'], $result->entityTypes);
    }

    #[Test]
    public function importDelegatesToCmsService(): void
    {
        $cmsResult = new CmsImportResult(
            created: ['content' => 3],
            updated: ['content' => 1],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $this->service->method('importUnifiedFile')->willReturn($cmsResult);

        $result = $this->provider->import(new ImportRequest(content: '{}'));

        self::assertSame('cms', $result->providerName);
        self::assertSame(3, $result->totalCreated());
        self::assertSame(1, $result->totalUpdated());
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function schemaDescribesAllEntityTypes(): void
    {
        $schema = $this->provider->schema();

        self::assertArrayHasKey('content', $schema);
        self::assertArrayHasKey('taxonomies', $schema);
        self::assertArrayHasKey('menus', $schema);
        self::assertArrayHasKey('settings', $schema);
        self::assertArrayHasKey('media', $schema);
        self::assertArrayHasKey('comments', $schema);
        self::assertArrayHasKey('users', $schema);
        self::assertArrayHasKey('configuration', $schema);

        // Media should include SEO fields
        self::assertArrayHasKey('alt', $schema['media']);
        self::assertArrayHasKey('title', $schema['media']);
        self::assertArrayHasKey('caption', $schema['media']);
        self::assertArrayHasKey('description', $schema['media']);
    }
}
