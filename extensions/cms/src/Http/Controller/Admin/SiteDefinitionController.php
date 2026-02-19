<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;

/**
 * Admin controller for full site definition import.
 *
 * Processes N.3 schema site definitions which include taxonomies, media,
 * content, menus, settings, redirects, and SEO configuration.
 * All execute operations require step-up authentication.
 *
 * Uses the unified import backend so that both CLI and GUI share the same
 * code path, returning structured ImportResult with per-type counts.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class SiteDefinitionController extends AbstractAdminController
{
    public function __construct(
        private ImportExportServiceInterface $importExport,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function form(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        return $this->respondWithView($request, 'admin.tools.site-import', [
            'accepted_format' => 'application/json',
            'schema' => 'N.3',
            'max_size_bytes' => 50 * 1024 * 1024,
            'supports_dry_run' => true,
        ]);
    }

    public function dryRun(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        $jsonContent = $this->extractJsonContent($request);

        if ($jsonContent === null) {
            return Response::json(['error' => 'JSON site definition is required'], 400);
        }

        try {
            $result = $this->importExport->importUnifiedFile($jsonContent, dryRun: true);

            return Response::json($result->toArray());
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function execute(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');
        $this->requireStepUp($request);

        $jsonContent = $this->extractJsonContent($request);

        if ($jsonContent === null) {
            return Response::json(['error' => 'JSON site definition is required'], 400);
        }

        try {
            $result = $this->importExport->importUnifiedFile($jsonContent, dryRun: false);

            return Response::json([
                'status' => 'imported',
                'result' => $result->toArray(),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function extractJsonContent(ServerRequestInterface $request): ?string
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $content = $body['json_content'] ?? null;

        if (is_string($content) && $content !== '') {
            return $content;
        }

        $rawBody = (string) $request->getBody();

        return $rawBody !== '' ? $rawBody : null;
    }
}
