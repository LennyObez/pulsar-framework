<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

/**
 * Admin controller for the live CSS editor.
 *
 * Provides theme token editing, CSS override management, version history,
 * and rollback capabilities. All operations are versioned and auditable.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class LiveCssController extends AbstractAdminController
{
    public function __construct(
        private LiveCssServiceInterface $liveCss,
        private CssValidatorInterface $validator,
        private ThemeTokenResolverInterface $tokenResolver,
        private ThemeManagerInterface $themeManager,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function editor(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.manage');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $activeTheme = $this->themeManager->getActive($tenantId);

        if ($activeTheme === null) {
            return Response::json(['error' => 'No active theme found'], 404);
        }

        $tokens = $this->tokenResolver->getEditableTokens($activeTheme->id);
        $currentOverrides = $this->liveCss->getCurrentOverrides($activeTheme->id, $tenantId);

        return $this->respondWithView($request, 'admin.live-css.editor', [
            'theme' => [
                'id' => $activeTheme->id,
                'slug' => $activeTheme->slug,
                'display_name' => $activeTheme->displayName,
            ],
            'tokens' => array_map(static fn($token) => [
                'name' => $token->name,
                'type' => $token->type,
                'default' => $token->default,
                'label' => $token->label,
                'group' => $token->group,
                'constraints' => $token->constraints,
            ], $tokens),
            'current_overrides' => $currentOverrides !== null ? [
                'id' => $currentOverrides->id,
                'version' => $currentOverrides->version,
                'css_content' => $currentOverrides->cssContent,
                'token_overrides' => $currentOverrides->tokenOverrides,
                'created_at' => $currentOverrides->createdAt->format('c'),
                'created_by' => $currentOverrides->createdBy,
                'reason' => $currentOverrides->reason,
            ] : null,
        ]);
    }

    public function save(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawThemeId */
        $rawThemeId = $body['theme_id'] ?? null;
        $themeId = is_string($rawThemeId) ? $rawThemeId : '';
        /** @var mixed $rawCss */
        $rawCss = $body['css_content'] ?? null;
        $cssContent = is_string($rawCss) ? $rawCss : '';
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if ($themeId === '') {
            return Response::json(['error' => 'Theme ID is required'], 400);
        }

        if (strlen($reason) < 5) {
            return Response::json(['error' => 'A reason of at least 5 characters is required'], 400);
        }

        // Validate CSS content
        $validation = $this->validator->validate($cssContent);

        if (!$validation->isValid) {
            return Response::json([
                'error' => 'CSS validation failed',
                'violations' => $validation->errors,
            ], 422);
        }

        /** @var array<string, string> $tokenOverrides */
        $tokenOverrides = is_array($body['token_overrides'] ?? null) ? $body['token_overrides'] : [];

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $override = $this->liveCss->saveOverrides(
                $themeId,
                $validation->sanitizedCss,
                $tokenOverrides,
                $reason,
                $identity->id(),
                $tenantId,
            );

            return Response::json([
                'id' => $override->id,
                'version' => $override->version,
                'css_hash' => $override->cssHash,
                'status' => 'saved',
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function rollback(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawOverrideId */
        $rawOverrideId = $body['override_id'] ?? null;
        $overrideId = is_string($rawOverrideId) ? $rawOverrideId : '';
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : '';

        if ($overrideId === '') {
            return Response::json(['error' => 'Override ID is required'], 400);
        }

        if (strlen($reason) < 5) {
            return Response::json(['error' => 'A reason of at least 5 characters is required for rollback'], 400);
        }

        try {
            $override = $this->liveCss->rollback($overrideId, $reason, $identity->id());

            return Response::json([
                'id' => $override->id,
                'version' => $override->version,
                'status' => 'rolled_back',
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function history(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.themes.view');

        $params = $request->getQueryParams();
        $themeId = is_string($params['theme_id'] ?? null) ? $params['theme_id'] : '';
        $page = max(1, is_int($params['page'] ?? null) ? $params['page'] : 1);
        $perPage = min(100, max(1, is_int($params['per_page'] ?? null) ? $params['per_page'] : 20));

        if ($themeId === '') {
            return Response::json(['error' => 'Theme ID is required'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $history = $this->liveCss->getVersionHistory($themeId, $tenantId, $page, $perPage);

        return Response::json([
            'data' => array_map(static fn($override) => [
                'id' => $override->id,
                'version' => $override->version,
                'css_hash' => $override->cssHash,
                'is_active' => $override->isActive,
                'created_at' => $override->createdAt->format('c'),
                'created_by' => $override->createdBy,
                'reason' => $override->reason,
            ], $history),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

}
