<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function count;
use function is_string;

/**
 * Admin controller for CMS site settings management.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class SettingsController
{
    public function __construct(
        private SettingsServiceInterface $settingsService,
        private GateInterface $gate,
        private CmsConfig $config,
    ) {}

    public function show(ServerRequestInterface $request, string $group): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.view');

        $locale = $this->resolveLocale($request);
        $settings = $this->settingsService->getGroup($group, $locale);

        return Response::json([
            'group' => $group,
            'locale' => $locale,
            'settings' => $settings,
        ]);
    }

    public function update(ServerRequestInterface $request, string $group): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : null;
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;

        /** @var array<string, mixed> $settings */
        $settings = (array) ($body['settings'] ?? []);

        foreach ($settings as $key => $value) {
            $this->settingsService->set($group, $key, $value, $locale, $reason);
        }

        return Response::json([
            'group' => $group,
            'status' => 'updated',
            'keys_updated' => count($settings),
        ]);
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

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }
}
