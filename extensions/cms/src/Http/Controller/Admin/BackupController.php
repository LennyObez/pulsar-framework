<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_bool;
use function is_string;
use function strlen;

/**
 * Admin controller for CMS backup and restore operations.
 *
 * Restore and delete operations require step-up authentication and a mandatory reason.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class BackupController
{
    use RendersAdminView;

    private const int CREATE_RATE_LIMIT_PER_MINUTE = 1;

    public function __construct(
        private BackupServiceInterface $backupService,
        private ?CmsRateLimiter $rateLimiter,
        private ?GateInterface $gate = null,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.backup');

        $tenantId = $this->validateTenantAccess($request);

        $backups = $this->backupService->listBackups($tenantId);

        return $this->respondWithView($request, 'admin.tools.backups', [
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

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('backup_create:' . $identity->id(), self::CREATE_RATE_LIMIT_PER_MINUTE)) {
            return Response::json(['error' => 'Too many requests'], 429);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $tenantId = $this->validateTenantAccess($request);

        $scope = BackupScope::fromArray([
            'include_content' => is_bool($body['include_content'] ?? null) ? $body['include_content'] : true,
            'include_media' => is_bool($body['include_media'] ?? null) ? $body['include_media'] : false,
            'include_taxonomies' => is_bool($body['include_taxonomies'] ?? null) ? $body['include_taxonomies'] : true,
            'include_menus' => is_bool($body['include_menus'] ?? null) ? $body['include_menus'] : true,
            'include_settings' => is_bool($body['include_settings'] ?? null) ? $body['include_settings'] : true,
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

}
