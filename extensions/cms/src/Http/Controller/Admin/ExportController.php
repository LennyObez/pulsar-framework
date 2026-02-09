<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Tools\CsvContentExporter;
use Pulsar\Extension\Cms\Internal\Tools\MarkdownExporter;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Admin controller for CMS data export.
 *
 * Generates a portable JSON bundle with an evidence hash for integrity
 * verification. The evidence hash is included as a response header.
 * Also supports Markdown and CSV export formats.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ExportController
{
    use RendersAdminView;

    public function __construct(
        private ImportExportServiceInterface $importExport,
        private GateInterface $gate,
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    public function form(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        return $this->respondWithView($request, 'admin.tools.export', [
            'scopes' => ['content', 'taxonomies', 'menus', 'settings', 'media_refs'],
            'options' => [
                'include_pii' => false,
                'locales' => null,
            ],
        ]);
    }

    public function download(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var list<string> $scope */
        $scope = is_array($body['scope'] ?? null) ? $body['scope'] : [];

        if ($scope === []) {
            return Response::json(['error' => 'At least one export scope is required'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $options = ExportOptions::fromArray([
                'scope' => $scope,
                'locales' => is_array($body['locales'] ?? null) ? $body['locales'] : null,
                'include_pii' => (bool) ($body['include_pii'] ?? false),
                'tenant_id' => $tenantId,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        $bundle = $this->importExport->exportBundle($options);

        $json = json_encode($bundle->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="cms-export.json"',
                'X-Evidence-Hash' => $bundle->evidenceHash,
            ],
            body: $json,
        );
    }

    public function markdownExport(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        $params = $request->getQueryParams();
        $locale = (string) ($params['locale'] ?? 'en');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        /** @var string|null $contentType */
        $contentType = $params['content_type'] ?? null;

        /** @var string|null $status */
        $status = $params['status'] ?? null;

        $result = $this->contentRepository->findPublished(
            locale: $locale,
            contentType: $contentType,
            perPage: 10000,
            tenantId: $tenantId,
        );

        $exporter = new MarkdownExporter();
        $items = [];

        foreach ($result->items as $content) {
            $translation = $this->translationRepository->findByContentAndLocale($content->id, $locale);

            if ($translation === null) {
                continue;
            }

            $blocks = $this->blockRepository->findByContentAndLocale($content->id, $locale);

            $items[] = [
                'content' => $content,
                'translation' => $translation,
                'blocks' => $blocks,
            ];
        }

        $markdown = $exporter->exportAll($items);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="cms-export.md"',
            ],
            body: $markdown,
        );
    }

    public function csvExport(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        $params = $request->getQueryParams();
        $locale = (string) ($params['locale'] ?? 'en');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        /** @var string|null $contentType */
        $contentType = $params['content_type'] ?? null;

        $result = $this->contentRepository->findPublished(
            locale: $locale,
            contentType: $contentType,
            perPage: 10000,
            tenantId: $tenantId,
        );

        $exporter = new CsvContentExporter();
        $items = [];

        foreach ($result->items as $content) {
            $translation = $this->translationRepository->findByContentAndLocale($content->id, $locale);

            if ($translation === null) {
                continue;
            }

            $items[] = [
                'content' => $content,
                'translation' => $translation,
            ];
        }

        $csv = $exporter->export($items);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="cms-export.csv"',
            ],
            body: $csv,
        );
    }
}
