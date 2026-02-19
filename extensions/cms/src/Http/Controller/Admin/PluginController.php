<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Plugins\CmsPluginManagerInterface;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function count;
use function file_exists;
use function is_string;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Admin controller for CMS plugin management.
 *
 * Provides listing installed plugins, installing from ZIP archive,
 * enabling/disabling, reading and updating plugin settings, and deleting.
 * Dangerous operations (install, toggle, delete) require step-up auth.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class PluginController
{
    public function __construct(
        private CmsPluginManagerInterface $pluginManager,
        private SettingsServiceInterface $settingsService,
        private GateInterface $gate,
    ) {}

    /**
     * List all installed plugins.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.view');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $plugins = $this->pluginManager->getInstalled($tenantId);

        return Response::json([
            'data' => array_map(static fn(InstalledCmsPlugin $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'display_name' => $p->displayName,
                'version' => $p->version,
                'description' => $p->description,
                'author_name' => $p->authorName,
                'author_url' => $p->authorUrl,
                'license' => $p->license,
                'capabilities' => $p->capabilities,
                'is_enabled' => $p->isEnabled,
                'provenance_verified' => $p->provenanceVerified,
                'signature_verified' => $p->signatureVerified,
                'installed_at' => $p->installedAt->format('c'),
                'installed_by' => $p->installedBy,
                'enabled_at' => $p->enabledAt?->format('c'),
            ], $plugins),
        ]);
    }

    /**
     * Upload and install a plugin from a ZIP archive.
     *
     * Requires step-up authentication.
     */
    public function install(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.install');
        $this->requireStepUp($request);

        $uploadedFiles = $request->getUploadedFiles();

        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No valid ZIP file uploaded'], 400);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pulsar_plugin_');

        if ($tempPath === false) {
            return Response::json(['error' => 'Failed to create temporary file'], 500);
        }

        $file->moveTo($tempPath);

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $plugin = $this->pluginManager->install($tempPath, $identity->id(), $tenantId);

            return Response::json([
                'id' => $plugin->id,
                'slug' => $plugin->slug,
                'display_name' => $plugin->displayName,
                'version' => $plugin->version,
                'provenance_verified' => $plugin->provenanceVerified,
                'signature_verified' => $plugin->signatureVerified,
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
     * Enable or disable a plugin (toggle).
     *
     * Requires step-up authentication.
     */
    public function toggle(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.manage');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $enabled = (bool) ($body['enabled'] ?? false);

        try {
            $plugin = $enabled
                ? $this->pluginManager->enable($id, $identity->id())
                : $this->pluginManager->disable($id, $identity->id());

            return Response::json([
                'id' => $plugin->id,
                'slug' => $plugin->slug,
                'is_enabled' => $plugin->isEnabled,
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Get settings for a specific plugin.
     */
    public function settings(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.manage');

        $settingsGroup = "plugin.{$id}";
        $locale = $this->resolveLocale($request);
        $settings = $this->settingsService->getGroup($settingsGroup, $locale);

        return Response::json([
            'plugin_id' => $id,
            'settings' => $settings,
        ]);
    }

    /**
     * Update settings for a specific plugin.
     */
    public function updateSettings(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var array<string, mixed> $settings */
        $settings = (array) ($body['settings'] ?? []);

        $settingsGroup = "plugin.{$id}";
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : null;
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;

        foreach ($settings as $key => $value) {
            $this->settingsService->set($settingsGroup, $key, $value, $locale, $reason);
        }

        return Response::json([
            'plugin_id' => $id,
            'status' => 'updated',
            'keys_updated' => count($settings),
        ]);
    }

    /**
     * Delete a plugin with a mandatory reason.
     *
     * Requires step-up authentication.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.delete');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for plugin deletion',
            ], 400);
        }

        try {
            $this->pluginManager->delete($id, $identity->id(), $reason);

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

    private function resolveLocale(ServerRequestInterface $request): ?string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : null;
    }
}
