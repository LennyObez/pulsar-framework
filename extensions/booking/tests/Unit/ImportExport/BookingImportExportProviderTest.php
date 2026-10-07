<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\ImportExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\ImportExport\BookingImportExportProvider;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ImportRequest;

#[CoversClass(BookingImportExportProvider::class)]
final class BookingImportExportProviderTest extends TestCase
{
    private BookingImportExportProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new BookingImportExportProvider();
    }

    public function testName(): void
    {
        self::assertSame('booking', $this->provider->name());
    }

    public function testLabel(): void
    {
        self::assertSame('Booking & Appointments', $this->provider->label());
    }

    public function testSupportedFormats(): void
    {
        $formats = $this->provider->supportedFormats();

        self::assertContains('json', $formats);
        self::assertContains('csv', $formats);
    }

    public function testExportReturnsAllEntityTypes(): void
    {
        $request = new ExportRequest(format: 'json');
        $result = $this->provider->export($request);

        self::assertSame('booking', $result->providerName);
        self::assertContains('appointments', $result->entityTypes);
        self::assertContains('services', $result->entityTypes);
        self::assertContains('categories', $result->entityTypes);
        self::assertNotEmpty($result->evidenceHash);
    }

    public function testExportFiltersEntityTypes(): void
    {
        $request = new ExportRequest(
            format: 'json',
            entityTypes: ['services'],
        );
        $result = $this->provider->export($request);

        self::assertContains('services', $result->entityTypes);
        self::assertNotContains('appointments', $result->entityTypes);
    }

    public function testImportReturnsDryRunResult(): void
    {
        $request = new ImportRequest(
            content: '{"appointments":[]}',
            format: 'json',
            dryRun: true,
        );

        $result = $this->provider->import($request);

        self::assertSame('booking', $result->providerName);
        self::assertTrue($result->dryRun);
        self::assertFalse($result->hasErrors());
    }

    public function testSchemaDescribesAllEntities(): void
    {
        $schema = $this->provider->schema();

        self::assertArrayHasKey('appointments', $schema);
        self::assertArrayHasKey('services', $schema);
        self::assertArrayHasKey('categories', $schema);

        self::assertArrayHasKey('booking_number', $schema['appointments']);
        self::assertArrayHasKey('name', $schema['services']);
        self::assertArrayHasKey('slug', $schema['categories']);
    }
}
