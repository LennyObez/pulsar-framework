<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\Internal\InMemoryDataPortabilityService;
use Pulsar\Extension\DataAct\Portability\ExportStatus;

#[CoversClass(InMemoryDataPortabilityService::class)]
final class InMemoryDataPortabilityServiceTest extends TestCase
{
    #[Test]
    public function requestExportCreatesNewPendingRequest(): void
    {
        $service = $this->createService();

        $request = $service->requestExport('user-1', 'json', ['profile']);

        self::assertNotEmpty($request->id);
        self::assertSame('user-1', $request->userId);
        self::assertSame('json', $request->format);
        self::assertSame(['profile'], $request->scopes);
        self::assertSame(ExportStatus::Pending, $request->status);
        self::assertNull($request->dataPath);
        self::assertNull($request->fulfilledAt);
    }

    #[Test]
    public function requestExportUsesDefaultFormatWhenEmpty(): void
    {
        $service = $this->createService(new DataActConfig(defaultExportFormat: 'csv'));

        $request = $service->requestExport('user-1', '');

        self::assertSame('csv', $request->format);
    }

    #[Test]
    public function requestExportSetsDeadlineBasedOnConfig(): void
    {
        $service = $this->createService(new DataActConfig(portabilityMaxDays: 15));

        $request = $service->requestExport('user-1', 'json');

        $daysDifference = $request->deadline->diff($request->requestedAt)->days;
        self::assertSame(15, $daysDifference);
    }

    #[Test]
    public function getExportStatusReturnsNullForUnknown(): void
    {
        $service = $this->createService();

        self::assertNull($service->getExportStatus('nonexistent'));
    }

    #[Test]
    public function getExportStatusReturnsExistingRequest(): void
    {
        $service = $this->createService();
        $created = $service->requestExport('user-1', 'json');

        $fetched = $service->getExportStatus($created->id);

        self::assertNotNull($fetched);
        self::assertSame($created->id, $fetched->id);
    }

    #[Test]
    public function fulfillExportUpdatesStatusAndDataPath(): void
    {
        $service = $this->createService();
        $request = $service->requestExport('user-1', 'json');

        $service->fulfillExport($request->id, '/exports/user-1.json');

        $fulfilled = $service->getExportStatus($request->id);

        self::assertNotNull($fulfilled);
        self::assertSame(ExportStatus::Fulfilled, $fulfilled->status);
        self::assertSame('/exports/user-1.json', $fulfilled->dataPath);
        self::assertNotNull($fulfilled->fulfilledAt);
    }

    #[Test]
    public function fulfillExportIgnoresUnknownRequestId(): void
    {
        $service = $this->createService();

        $service->fulfillExport('nonexistent', '/path');

        self::assertNull($service->getExportStatus('nonexistent'));
    }

    #[Test]
    public function listUserRequestsReturnsOnlyUserRequests(): void
    {
        $service = $this->createService();
        $service->requestExport('user-1', 'json');
        $service->requestExport('user-2', 'csv');
        $service->requestExport('user-1', 'xml');

        $user1Requests = $service->listUserRequests('user-1');
        $user2Requests = $service->listUserRequests('user-2');
        $user3Requests = $service->listUserRequests('user-3');

        self::assertCount(2, $user1Requests);
        self::assertCount(1, $user2Requests);
        self::assertCount(0, $user3Requests);
    }

    #[Test]
    public function cancelExportSetsCancelledStatus(): void
    {
        $service = $this->createService();
        $request = $service->requestExport('user-1', 'json');

        $service->cancelExport($request->id);

        $cancelled = $service->getExportStatus($request->id);

        self::assertNotNull($cancelled);
        self::assertSame(ExportStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function cancelExportIgnoresNonPendingRequest(): void
    {
        $service = $this->createService();
        $request = $service->requestExport('user-1', 'json');

        $service->fulfillExport($request->id, '/data.json');
        $service->cancelExport($request->id);

        $result = $service->getExportStatus($request->id);

        self::assertNotNull($result);
        self::assertSame(ExportStatus::Fulfilled, $result->status);
    }

    #[Test]
    public function cancelExportIgnoresUnknownRequest(): void
    {
        $service = $this->createService();

        $service->cancelExport('nonexistent');

        self::assertNull($service->getExportStatus('nonexistent'));
    }

    private function createService(?DataActConfig $config = null): InMemoryDataPortabilityService
    {
        return new InMemoryDataPortabilityService($config ?? new DataActConfig());
    }
}
