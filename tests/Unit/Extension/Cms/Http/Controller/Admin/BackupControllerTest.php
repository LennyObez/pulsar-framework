<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\BackupController;
use Pulsar\Extension\Cms\Tools\Backup;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\RestoreResult;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(BackupController::class)]
final class BackupControllerTest extends TestCase
{
    #[Test]
    public function index_returns_backup_list(): void
    {
        $backup = $this->createBackup('backup-1');

        $service = $this->createStub(BackupServiceInterface::class);
        $service->method('listBackups')->willReturn([$backup]);

        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $data */
        $data = $body['data'];
        self::assertCount(1, $data);
        self::assertSame('backup-1', $data[0]['id']);
        self::assertSame('blake2b_hash', $data[0]['hash']);
    }

    #[Test]
    public function create_returns_201_with_backup(): void
    {
        $backup = $this->createBackup('backup-new');

        $service = $this->createStub(BackupServiceInterface::class);
        $service->method('createBackup')->willReturn($backup);

        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'include_content' => true,
            'include_media' => false,
        ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('backup-new', $body['id']);
        self::assertSame('created', $body['status']);
    }

    #[Test]
    public function restore_returns_success_with_valid_reason(): void
    {
        $result = new RestoreResult(
            restoredCounts: ['content' => 10, 'menus' => 3],
            warnings: [],
        );

        $service = $this->createStub(BackupServiceInterface::class);
        $service->method('restoreBackup')->willReturn($result);

        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'Restoring after accidental deletion of content'],
        );

        $response = $controller->restore($request, 'backup-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('restored', $body['status']);
        self::assertIsArray($body['restored_counts']);

        /** @var array<string, int> $counts */
        $counts = $body['restored_counts'];
        self::assertSame(10, $counts['content']);
    }

    #[Test]
    public function restore_returns_400_when_reason_too_short(): void
    {
        $service = $this->createStub(BackupServiceInterface::class);
        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'short'],
        );

        $response = $controller->restore($request, 'backup-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function restore_returns_422_on_service_exception(): void
    {
        $service = $this->createStub(BackupServiceInterface::class);
        $service->method('restoreBackup')->willThrowException(new CmsException('Backup not found'));

        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'Need to restore after data corruption issue'],
        );

        $response = $controller->restore($request, 'nonexistent');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success_with_valid_reason(): void
    {
        $service = $this->createStub(BackupServiceInterface::class);
        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'reason' => 'Old backup no longer needed for compliance',
        ]);

        $response = $controller->delete($request, 'backup-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_400_when_reason_too_short(): void
    {
        $service = $this->createStub(BackupServiceInterface::class);
        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'reason' => 'too short',
        ]);

        $response = $controller->delete($request, 'backup-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_422_on_service_exception(): void
    {
        $service = $this->createStub(BackupServiceInterface::class);
        $service->method('deleteBackup')->willThrowException(new CmsException('Backup not found'));

        $controller = new BackupController(backupService: $service, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'reason' => 'Removing outdated backup from storage',
        ]);

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $service = $this->createStub(BackupServiceInterface::class);
        $controller = new BackupController(backupService: $service, rateLimiter: null);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $service = $this->createStub(BackupServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new BackupController(backupService: $service, rateLimiter: null, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createBackup(string $id): Backup
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Backup(
            id: $id,
            scope: BackupScope::fromArray([
                'include_content' => true,
                'include_media' => false,
                'include_taxonomies' => true,
                'include_menus' => true,
                'include_settings' => true,
            ]),
            storagePath: 'backups/' . $id . '.json',
            hash: 'blake2b_hash',
            size: 102400,
            createdAt: $now,
            createdBy: 'admin-1',
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/backups');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/backups');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
