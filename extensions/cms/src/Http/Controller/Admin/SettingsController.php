<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function count;
use function is_array;
use function is_string;

/**
 * Admin controller for CMS site settings management.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class SettingsController extends AbstractAdminController
{
    public function __construct(
        private SettingsServiceInterface $settingsService,
        private CmsConfig $config,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function show(ServerRequestInterface $request, string $group): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.view');

        $locale = $this->resolveLocale($request);
        $settings = $this->settingsService->getGroup($group, $locale);

        $data = [
            'group' => $group,
            'locale' => $locale,
            'settings' => $settings,
        ];

        return $this->respondWithView($request, 'admin.settings.form', $data);
    }

    public function update(ServerRequestInterface $request, string $group): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.manage');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : null;
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;

        /** @var array<string, mixed> $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        foreach ($settings as $key => $value) {
            $this->settingsService->set($group, $key, $value, $locale, $reason);
        }

        return Response::json([
            'group' => $group,
            'status' => 'updated',
            'keys_updated' => count($settings),
        ]);
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }
}
