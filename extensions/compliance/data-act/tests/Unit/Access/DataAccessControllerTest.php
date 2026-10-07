<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Access\DataAccessController;
use Pulsar\Extension\DataAct\Access\ThirdPartyAccessPolicy;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\Internal\InMemoryDataPortabilityService;
use Pulsar\Extension\DataAct\Portability\ExportStatus;

#[CoversClass(DataAccessController::class)]
final class DataAccessControllerTest extends TestCase
{
    #[Test]
    public function requestExportDelegatesToPortabilityService(): void
    {
        $controller = $this->createController();

        $request = $controller->requestExport('user-1', 'json', ['profile']);

        self::assertSame('user-1', $request->userId);
        self::assertSame('json', $request->format);
        self::assertSame(['profile'], $request->scopes);
        self::assertSame(ExportStatus::Pending, $request->status);
    }

    #[Test]
    public function exportStatusReturnsRequest(): void
    {
        $controller = $this->createController();
        $created = $controller->requestExport('user-1', 'json');

        $fetched = $controller->exportStatus($created->id);

        self::assertNotNull($fetched);
        self::assertSame($created->id, $fetched->id);
    }

    #[Test]
    public function exportStatusReturnsNullForUnknown(): void
    {
        $controller = $this->createController();

        self::assertNull($controller->exportStatus('nonexistent'));
    }

    #[Test]
    public function requestThirdPartyAccessGrantsValidRequest(): void
    {
        $controller = $this->createController(enabled: true);

        $result = $controller->requestThirdPartyAccess('partner', 'user-1', 'research');

        self::assertTrue($result->granted);
    }

    #[Test]
    public function requestThirdPartyAccessDeniesProhibitedPurpose(): void
    {
        $controller = $this->createController(enabled: true);

        $result = $controller->requestThirdPartyAccess('partner', 'user-1', 'profiling');

        self::assertFalse($result->granted);
    }

    private function createController(bool $enabled = false): DataAccessController
    {
        $config = new DataActConfig(enabled: $enabled);

        return new DataAccessController(
            new InMemoryDataPortabilityService($config),
            new ThirdPartyAccessPolicy($config),
        );
    }
}
