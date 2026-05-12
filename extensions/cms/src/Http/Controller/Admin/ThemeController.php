<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function file_exists;
use function is_string;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Admin controller for theme management.
 *
 * Provides listing installed themes, installing from ZIP archive,
 * activating, deactivating, previewing, and deleting themes.
 * Dangerous operations (install, activate, delete) require step-up auth.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class ThemeController extends AbstractAdminController
{
    private const int INSTALL_RATE_LIMIT_PER_MINUTE = 2;

    public function __construct(
        private ThemeManagerInterface $themeManager,
        private ?CmsRateLimiter $rateLimiter,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * List all installed themes.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.view');

        $tenantId = $this->validateTenantAccess($request);

        $themes = $this->themeManager->getInstalled($tenantId);

        $data = [
            'themes' => array_map(static fn(InstalledTheme $t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'display_name' => $t->displayName,
                'version' => $t->version,
                'description' => $t->description,
                'author_name' => $t->authorName,
                'author_url' => $t->authorUrl,
                'license' => $t->license,
                'is_active' => $t->isActive,
                'provenance_verified' => $t->provenanceVerified,
                'signature_verified' => $t->signatureVerified,
                'installed_at' => $t->installedAt->format('c'),
                'installed_by' => $t->installedBy,
                'activated_at' => $t->activatedAt?->format('c'),
            ], $themes),
        ];

        return $this->respondWithView($request, 'admin.themes.index', $data);
    }

    /**
     * Upload and install a theme from a ZIP archive.
     *
     * Requires step-up authentication.
     */
    public function install(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.install');
        $this->requireStepUp($request);

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('theme_install:' . $identity->id(), self::INSTALL_RATE_LIMIT_PER_MINUTE)) {
            return Response::json(['error' => 'Too many requests'], 429);
        }

        $uploadedFiles = $request->getUploadedFiles();

        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No valid ZIP file uploaded'], 400);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pulsar_theme_');

        if ($tempPath === false) {
            return Response::json(['error' => 'Failed to create temporary file'], 500);
        }

        $file->moveTo($tempPath);

        $tenantId = $this->validateTenantAccess($request);

        try {
            $theme = $this->themeManager->install($tempPath, $identity->id(), $tenantId);

            return Response::json([
                'id' => $theme->id,
                'slug' => $theme->slug,
                'display_name' => $theme->displayName,
                'version' => $theme->version,
                'provenance_verified' => $theme->provenanceVerified,
                'signature_verified' => $theme->signatureVerified,
            ], 201);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Activate an installed theme.
     *
     * Requires step-up authentication.
     */
    public function activate(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.manage');
        $this->requireStepUp($request);

        try {
            $theme = $this->themeManager->activate($id, $identity->id());

            return Response::json([
                'id' => $theme->id,
                'slug' => $theme->slug,
                'is_active' => $theme->isActive,
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Deactivate an active theme.
     */
    public function deactivate(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.manage');

        try {
            $theme = $this->themeManager->deactivate($id, $identity->id());

            return Response::json([
                'id' => $theme->id,
                'slug' => $theme->slug,
                'is_active' => $theme->isActive,
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Generate a theme preview session URL.
     */
    public function preview(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.view');

        try {
            $session = $this->themeManager->preview($id, $identity->id());

            return Response::json([
                'theme_id' => $session->themeId,
                'token' => $session->token,
                'expires_at' => $session->expiresAt->format('c'),
                'preview_url' => '/?theme_preview=' . $session->token,
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Delete an installed theme with a mandatory reason.
     *
     * Requires step-up authentication.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.delete');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for theme deletion',
            ], 400);
        }

        try {
            $this->themeManager->delete($id, $identity->id(), $reason);

            return Response::json(['id' => $id, 'status' => 'deleted']);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
