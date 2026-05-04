<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Account\AccountSectionRegistry;
use Pulsar\Extension\Cms\Users\CmsUser;
use Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function str_starts_with;
use function strlen;

/**
 * Admin controller for CMS user management.
 *
 * Lists users with CMS roles, shows user details with role badges,
 * 2FA status, and extension-contributed tabs (orders, forum activity, etc.).
 * Updates roles (with step-up) and resets 2FA.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class UserController extends AbstractAdminController
{
    public function __construct(
        private CmsUserRepositoryInterface $userRepository,
        private ?AuditLoggerInterface $auditLogger,
        private ?AccountSectionRegistry $sectionRegistry = null,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * List CMS users with role badges and 2FA status.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.view');

        $params = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));
        /** @var mixed $rawRole */
        $rawRole = $params['role'] ?? null;
        $role = is_string($rawRole) ? $rawRole : null;

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->userRepository->listUsers(
            tenantId: $tenantId,
            role: $role,
            page: $page,
            perPage: $perPage,
        );

        $data = [
            'users' => array_map(static fn(CmsUser $u) => [
                'id' => $u->id,
                'display_name' => $u->displayName,
                'email' => $u->email,
                'roles' => $u->roles,
                'two_factor_status' => $u->twoFactorStatus->value,
                'content_count' => $u->contentCount,
                'comment_count' => $u->commentCount,
                'last_active_at' => $u->lastActiveAt?->format('c'),
                'created_at' => $u->createdAt->format('c'),
                'is_locked' => $u->isLocked,
            ], $result->items),
            'pagination' => $result->metaToArray(),
        ];

        return $this->respondWithView($request, 'admin.users.index', $data);
    }

    /**
     * Show detailed user information with extension-contributed tabs.
     *
     * When extensions like Forum or Payments are active, their
     * AccountSectionProviders contribute tabs showing orders,
     * forum activity, badges, invoices, etc.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.view');

        $user = $this->userRepository->findById($id);

        if ($user === null) {
            return Response::json(['error' => 'User not found'], 404);
        }

        $params = $request->getQueryParams();
        /** @var mixed $rawSection */
        $rawSection = $params['section'] ?? null;
        $activeSection = is_string($rawSection) ? $rawSection : 'details';

        // Collect extension-contributed sections (orders, forum activity, etc.)
        $sections = $this->sectionRegistry?->getSections($id) ?? [];

        // Render extension section content if a non-built-in section is active
        $sectionHtml = '';

        if ($activeSection !== 'details' && $this->sectionRegistry !== null) {
            /** @var array<string, mixed> $sectionParams */
            $sectionParams = $params;

            $sectionHtml = $this->sectionRegistry->renderBackOffice(
                $activeSection,
                $id,
                $sectionParams,
            );
        }

        $data = [
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenantId,
                'display_name' => $user->displayName,
                'email' => $user->email,
                'roles' => $user->roles,
                'two_factor_status' => $user->twoFactorStatus->value,
                'content_count' => $user->contentCount,
                'comment_count' => $user->commentCount,
                'last_active_at' => $user->lastActiveAt?->format('c'),
                'created_at' => $user->createdAt->format('c'),
                'is_locked' => $user->isLocked,
            ],
            'sections' => $sections,
            'active_section' => $activeSection,
            'section_html' => $sectionHtml,
        ];

        return $this->respondWithView($request, 'admin.users.edit', $data);
    }

    /**
     * Update a CMS user (role assignment requires step-up).
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.manage');

        $user = $this->userRepository->findById($id);

        if ($user === null) {
            return Response::json(['error' => 'User not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        // Role changes require step-up authentication
        if (isset($body['roles'])) {
            $this->requireStepUp($request);

            if (!is_array($body['roles'])) {
                return Response::json(['error' => 'Roles must be an array'], 400);
            }

            /** @var list<string> $newRoles */
            $newRoles = array_map(static fn(mixed $v): string => is_string($v) ? $v : (is_scalar($v) ? (string) $v : ''), $body['roles']);

            // Validate that all roles are CMS roles
            foreach ($newRoles as $role) {
                if (!str_starts_with($role, 'cms.')) {
                    return Response::json([
                        'error' => "Invalid CMS role: $role",
                    ], 400);
                }
            }

            $this->userRepository->updateRoles($id, $newRoles);

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Success,
                $identity->id(),
                'cms.user.roles_updated',
                "user:$id",
                [
                    'old_roles' => $user->roles,
                    'new_roles' => $newRoles,
                ],
            );
        }

        return Response::json(['id' => $id, 'status' => 'updated']);
    }

    /**
     * Reset a user's two-factor authentication.
     *
     * Requires step-up authentication and a mandatory reason.
     */
    public function resetTwoFactor(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.users.manage');
        $this->requireStepUp($request);

        $user = $this->userRepository->findById($id);

        if ($user === null) {
            return Response::json(['error' => 'User not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for 2FA reset',
            ], 400);
        }

        $this->userRepository->resetTwoFactor($id);

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            $identity->id(),
            'cms.user.2fa_reset',
            "user:$id",
            ['reason' => $reason],
        );

        return Response::json([
            'id' => $id,
            'status' => 'two_factor_reset',
        ]);
    }
}
