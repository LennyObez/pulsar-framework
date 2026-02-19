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
use Pulsar\Extension\Cms\Tools\MediaBundleExporterInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function fclose;
use function filesize;
use function fopen;
use function is_array;
use function is_bool;
use function is_string;
use function json_encode;
use function stream_get_contents;
use function strlen;
use function unlink;

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
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class ExportController extends AbstractAdminController
{
    public function __construct(
        private ImportExportServiceInterface $importExport,
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        ?GateInterface $gate = null,
        private ?MediaBundleExporterInterface $mediaBundleExporter = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function form(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        return $this->respondWithView($request, 'admin.tools.export', [
            'scopes' => [
                'content',
                'taxonomies',
                'menus',
                'settings',
                'media_refs',
                'comments',
                'users',
                'media_files',
                'configuration',
            ],
            'options' => [
                'include_pii' => false,
                'locales' => null,
            ],
            'supports_zip' => $this->mediaBundleExporter !== null,
        ]);
    }

    public function selectiveForm(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        return $this->respondWithView($request, 'admin.tools.export', [
            'scopes' => [
                'content',
                'taxonomies',
                'menus',
                'settings',
                'media_refs',
                'comments',
                'users',
                'media_files',
                'configuration',
            ],
            'options' => [
                'include_pii' => false,
                'locales' => null,
            ],
            'supports_zip' => $this->mediaBundleExporter !== null,
            'selective' => true,
        ]);
    }

    public function zipDownload(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');

        if ($this->mediaBundleExporter === null) {
            return Response::json(['error' => 'ZIP export is not available'], 501);
        }

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
            /** @var list<string>|null $locales */
            $locales = is_array($body['locales'] ?? null) ? $body['locales'] : null;

            /** @var list<string>|null $contentTypes */
            $contentTypes = is_array($body['content_types'] ?? null) ? $body['content_types'] : null;

            $options = ExportOptions::fromArray([
                'scope' => $scope,
                'locales' => $locales,
                'include_pii' => is_bool($body['include_pii'] ?? null) ? $body['include_pii'] : false,
                'tenant_id' => $tenantId,
                'content_types' => $contentTypes,
                'date_from' => isset($body['date_from']) && $body['date_from'] !== '' ? (is_string($body['date_from']) ? $body['date_from'] : '') : null,
                'date_to' => isset($body['date_to']) && $body['date_to'] !== '' ? (is_string($body['date_to']) ? $body['date_to'] : '') : null,
                'status' => isset($body['status']) && $body['status'] !== '' ? (is_string($body['status']) ? $body['status'] : '') : null,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        if ($options->includePii) {
            $this->requireStepUp($request);
        }

        $zipPath = $this->mediaBundleExporter->exportZip($options);
        $size = filesize($zipPath);

        // Stream the ZIP file to avoid loading the entire archive into memory.
        // This is important for large exports with many media files.
        $stream = fopen($zipPath, 'rb');

        if ($stream === false) {
            unlink($zipPath);

            return Response::json(['error' => 'Failed to read export file'], 500);
        }

        $body = stream_get_contents($stream);
        fclose($stream);
        unlink($zipPath);

        return new Response(
            headers: [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="cms-export.zip"',
                'Content-Length' => (string) ($size ?: strlen((string) $body)),
            ],
            body: (string) $body,
        );
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
            /** @var list<string>|null $locales */
            $locales = is_array($body['locales'] ?? null) ? $body['locales'] : null;
            $options = ExportOptions::fromArray([
                'scope' => $scope,
                'locales' => $locales,
                'include_pii' => is_bool($body['include_pii'] ?? null) ? $body['include_pii'] : false,
                'tenant_id' => $tenantId,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        if ($options->includePii) {
            $this->requireStepUp($request);
        }

        $bundle = $this->importExport->exportBundle($options);

        $json = json_encode($bundle->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response(
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
        $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'en';

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
        $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'en';

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
            headers: [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="cms-export.csv"',
            ],
            body: $csv,
        );
    }
}
