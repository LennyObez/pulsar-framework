<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function is_string;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Admin controller for theme management.
 *
 * Provides listing installed themes, installing from ZIP archive,
 * activating, deactivating, previewing, and deleting themes.
 * Dangerous operations (install, activate, delete) require step-up auth.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ThemeController
{
    public function __construct(
        private ThemeManagerInterface $themeManager,
        private GateInterface $gate,
    ) {}

    /**
     * List all installed themes.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.view');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $themes = $this->themeManager->getInstalled($tenantId);

        return Response::json([
            'data' => array_map(static fn(InstalledTheme $t) => [
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
        ]);
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

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

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
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

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

    /**
     * Require that the request has passed step-up authentication.
     *
     * Step-up auth is verified via a request attribute set by middleware.
     */
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
