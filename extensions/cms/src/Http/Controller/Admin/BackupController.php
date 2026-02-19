<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function is_string;
use function strlen;

/**
 * Admin controller for CMS backup and restore operations.
 *
 * Restore and delete operations require step-up authentication and a mandatory reason.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class BackupController
{
    public function __construct(
        private BackupServiceInterface $backupService,
        private GateInterface $gate,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.backup');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $backups = $this->backupService->listBackups($tenantId);

        return Response::json([
            'data' => array_map(static fn($backup) => [
                'id' => $backup->id,
                'scope' => $backup->scope->toArray(),
                'hash' => $backup->hash,
                'size' => $backup->size,
                'created_at' => $backup->createdAt->format('c'),
                'created_by' => $backup->createdBy,
            ], $backups),
        ]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.backup');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $scope = BackupScope::fromArray([
            'include_content' => (bool) ($body['include_content'] ?? true),
            'include_media' => (bool) ($body['include_media'] ?? false),
            'include_taxonomies' => (bool) ($body['include_taxonomies'] ?? true),
            'include_menus' => (bool) ($body['include_menus'] ?? true),
            'include_settings' => (bool) ($body['include_settings'] ?? true),
            'tenant_id' => $tenantId,
        ]);

        $backup = $this->backupService->createBackup($scope, $identity->id());

        return Response::json([
            'id' => $backup->id,
            'hash' => $backup->hash,
            'size' => $backup->size,
            'created_at' => $backup->createdAt->format('c'),
            'status' => 'created',
        ], 201);
    }

    public function restore(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.backup');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for backup restore',
            ], 400);
        }

        try {
            $result = $this->backupService->restoreBackup($id, $reason, $identity->id());

            return Response::json([
                'backup_id' => $id,
                'status' => 'restored',
                'restored_counts' => $result->restoredCounts,
                'warnings' => $result->warnings,
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.backup');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for backup deletion',
            ], 400);
        }

        try {
            $this->backupService->deleteBackup($id, $reason, $identity->id());

            return Response::json(['id' => $id, 'status' => 'deleted']);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function requireStepUp(ServerRequestInterface $request): void
    {
        $stepUp = $request->getAttribute('step_up_verified', false);

        if ($stepUp !== true) {
            throw new RuntimeException('Step-up authentication required for this action');
        }
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }
}
