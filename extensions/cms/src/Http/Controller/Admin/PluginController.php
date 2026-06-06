<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Plugins\CmsPluginManagerInterface;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function count;
use function file_exists;
use function is_array;
use function is_bool;
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
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class PluginController extends AbstractAdminController
{
    private const int INSTALL_RATE_LIMIT_PER_MINUTE = 2;

    public function __construct(
        private CmsPluginManagerInterface $pluginManager,
        private SettingsServiceInterface $settingsService,
        private ?CmsRateLimiter $rateLimiter,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * List all installed plugins.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.view');

        $tenantId = $this->validateTenantAccess($request);

        $plugins = $this->pluginManager->getInstalled($tenantId);

        $data = [
            'plugins' => array_map(static fn(InstalledCmsPlugin $p) => [
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
        ];

        return $this->respondWithView($request, 'admin.plugins.index', $data);
    }

    /**
     * Upload and install a plugin from a ZIP archive.
     *
     * Requires step-up authentication.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function install(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.install');
        $this->requireStepUp($request);

        if ($this->rateLimiter !== null && !$this->rateLimiter->attempt('plugin_install:' . $identity->id(), self::INSTALL_RATE_LIMIT_PER_MINUTE)) {
            return Response::json(['error' => 'Too many requests'], 429);
        }

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

        $tenantId = $this->validateTenantAccess($request);

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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function toggle(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.manage');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawEnabled */
        $rawEnabled = $body['enabled'] ?? null;
        $enabled = is_bool($rawEnabled) ? $rawEnabled : false;

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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function settings(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.manage');

        $settingsGroup = "plugin.$id";
        $locale = $this->resolveLocale($request);
        $settings = $this->settingsService->getGroup($settingsGroup, $locale);

        $data = [
            'plugin_id' => $id,
            'settings' => $settings,
        ];

        return $this->respondWithView($request, 'admin.plugins.settings', $data);
    }

    /**
     * Update settings for a specific plugin.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function updateSettings(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawSettings */
        $rawSettings = $body['settings'] ?? null;
        /** @var array<string, mixed> $settings */
        $settings = is_array($rawSettings) ? $rawSettings : [];

        $settingsGroup = "plugin.$id";
        /** @var mixed $rawLocale */
        $rawLocale = $body['locale'] ?? null;
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : null;
        $reason = is_string($rawReason) ? $rawReason : null;

        /** @var mixed $value */
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.plugins.delete');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

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

    private function resolveLocale(ServerRequestInterface $request): ?string
    {
        /** @var mixed $locale */
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : null;
    }
}
