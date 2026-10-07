<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Internal;

use DateInterval;
use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\Portability\DataPortabilityService;
use Pulsar\Extension\DataAct\Portability\ExportRequest;
use Pulsar\Extension\DataAct\Portability\ExportStatus;

use function bin2hex;
use function random_bytes;

/**
 * In-memory implementation of DataPortabilityService for development and testing.
 *
 * Production deployments should provide a persistent implementation backed
 * by database storage and background job processing.
 */
#[Internal(reason: 'Default in-memory implementation for dev/test')]
final class InMemoryDataPortabilityService extends DataPortabilityService
{
    /** @var array<string, ExportRequest> */
    private array $requests = [];

    public function __construct(
        private readonly DataActConfig $config,
    ) {}

    #[Override]
    public function requestExport(string $userId, string $format, array $scopes = []): ExportRequest
    {
        $id = bin2hex(random_bytes(16));
        $now = new DateTimeImmutable();
        $deadline = $now->add(new DateInterval('P' . $this->config->portabilityMaxDays . 'D'));

        $request = new ExportRequest(
            id: $id,
            userId: $userId,
            format: $format !== '' ? $format : $this->config->defaultExportFormat,
            scopes: $scopes,
            status: ExportStatus::Pending,
            requestedAt: $now,
            deadline: $deadline,
        );

        $this->requests[$id] = $request;

        return $request;
    }

    #[Override]
    public function getExportStatus(string $requestId): ?ExportRequest
    {
        return $this->requests[$requestId] ?? null;
    }

    #[Override]
    public function fulfillExport(string $requestId, string $dataPath): void
    {
        $request = $this->requests[$requestId] ?? null;

        if ($request === null) {
            return;
        }

        $this->requests[$requestId] = new ExportRequest(
            id: $request->id,
            userId: $request->userId,
            format: $request->format,
            scopes: $request->scopes,
            status: ExportStatus::Fulfilled,
            requestedAt: $request->requestedAt,
            deadline: $request->deadline,
            dataPath: $dataPath,
            fulfilledAt: new DateTimeImmutable(),
        );
    }

    #[Override]
    public function listUserRequests(string $userId): array
    {
        $result = [];

        foreach ($this->requests as $request) {
            if ($request->userId === $userId) {
                $result[] = $request;
            }
        }

        return $result;
    }

    #[Override]
    public function cancelExport(string $requestId): void
    {
        $request = $this->requests[$requestId] ?? null;

        if ($request === null || !$request->isPending()) {
            return;
        }

        $this->requests[$requestId] = new ExportRequest(
            id: $request->id,
            userId: $request->userId,
            format: $request->format,
            scopes: $request->scopes,
            status: ExportStatus::Cancelled,
            requestedAt: $request->requestedAt,
            deadline: $request->deadline,
        );
    }
}
