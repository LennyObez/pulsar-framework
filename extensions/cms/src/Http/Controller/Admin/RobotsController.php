<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;

/**
 * Admin controller for robots.txt management.
 *
 * Allows viewing and editing the robots.txt content stored in CMS settings.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class RobotsController extends AbstractAdminController
{
    private const string SETTINGS_GROUP = 'seo';
    private const string SETTINGS_KEY = 'robots_txt';
    private const string DEFAULT_ROBOTS = "User-agent: *\nAllow: /\n";

    public function __construct(
        private SettingsServiceInterface $settings,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * Show the current robots.txt content.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.view');

        /** @var mixed $content */
        $content = $this->settings->get(self::SETTINGS_GROUP, self::SETTINGS_KEY);

        $data = [
            'content' => is_string($content) ? $content : self::DEFAULT_ROBOTS,
        ];

        return $this->respondWithView($request, 'admin.seo.robots-preview', $data);
    }

    /**
     * Update the robots.txt content.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function update(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawContent */
        $rawContent = $body['content'] ?? null;
        $content = is_string($rawContent) ? $rawContent : '';

        if ($content === '') {
            return Response::json(['error' => 'Content is required'], 400);
        }

        $this->settings->set(
            self::SETTINGS_GROUP,
            self::SETTINGS_KEY,
            $content,
            null,
            'robots.txt updated via admin',
        );

        return Response::json([
            'status' => 'updated',
            'content' => $content,
        ]);
    }

}
