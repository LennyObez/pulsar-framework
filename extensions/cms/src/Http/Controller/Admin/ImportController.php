<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Tools\CsvContentImporter;
use Pulsar\Extension\Cms\Internal\Tools\MarkdownImporter;
use Pulsar\Extension\Cms\Internal\Tools\MediaBundleImporter;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Extension\Cms\Tools\DuplicateResolutionPolicy;
use Pulsar\Extension\Cms\Tools\ImportAnalyzer;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function count;
use function is_array;
use function is_string;
use function json_decode;

/**
 * Admin controller for CMS data import.
 *
 * Supports dry-run validation before committing changes.
 * Execute operations require step-up authentication.
 * Accepts JSON bundles, Markdown (with YAML frontmatter), and CSV formats.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class ImportController extends AbstractAdminController
{
    public function __construct(
        private ImportExportServiceInterface $importExport,
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private CsvContentImporter $csvImporter,
        ?GateInterface $gate = null,
        private ?ImportAnalyzer $importAnalyzer = null,
        private ?MediaBundleImporter $mediaBundleImporter = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function form(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        return $this->respondWithView($request, 'admin.tools.import', [
            'accepted_format' => 'application/json',
            'max_size_bytes' => 10 * 1024 * 1024,
            'supports_dry_run' => true,
        ]);
    }

    public function dryRun(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        $jsonContent = $this->extractJsonContent($request);

        if ($jsonContent === null) {
            return Response::json(['error' => 'JSON content is required'], 400);
        }

        try {
            $result = $this->importExport->importBundle($jsonContent, dryRun: true);

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
            return Response::json(['error' => 'JSON content is required'], 400);
        }

        try {
            $result = $this->importExport->importBundle($jsonContent, dryRun: false);

            return Response::json([
                'status' => 'imported',
                'result' => $result->toArray(),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function markdownImport(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        $markdownContent = $this->extractFileContent($request, 'markdown_content');

        if ($markdownContent === null) {
            return Response::json(['error' => 'Markdown content is required'], 400);
        }

        $importer = new MarkdownImporter();

        try {
            $parsed = $importer->parseAll($markdownContent);
        } catch (JsonException $e) {
            return Response::json(['error' => 'Invalid block data JSON: ' . $e->getMessage()], 422);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $dryRun = ($body['dry_run'] ?? '1') === '1';

        if ($dryRun) {
            return Response::json([
                'dry_run' => true,
                'items' => count($parsed),
                'preview' => array_map(
                    static fn(array $item): array => [
                        'title' => $item['translation']['title'],
                        'slug' => $item['translation']['slug'],
                        'content_type' => $item['content']['content_type'],
                        'blocks' => count($item['blocks']),
                    ],
                    $parsed,
                ),
            ]);
        }

        $this->requireStepUp($request);
        $created = 0;
        $errors = [];

        foreach ($parsed as $item) {
            try {
                $this->persistMarkdownItem($item);
                $created++;
            } catch (CmsException $e) {
                $errors[] = (is_string($item['translation']['title'] ?? null) ? $item['translation']['title'] : 'untitled') . ': ' . $e->getMessage();
            }
        }

        return Response::json([
            'status' => 'imported',
            'created' => $created,
            'errors' => $errors,
        ]);
    }

    public function csvImport(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        $csvContent = $this->extractFileContent($request, 'csv_content');

        if ($csvContent === null) {
            return Response::json(['error' => 'CSV content is required'], 400);
        }

        $parsed = $this->csvImporter->parse($csvContent);

        if ($parsed === []) {
            return Response::json(['error' => 'No valid rows found in CSV'], 422);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $dryRun = ($body['dry_run'] ?? '1') === '1';

        if ($dryRun) {
            return Response::json([
                'dry_run' => true,
                'items' => count($parsed),
                'preview' => array_map(
                    static fn(array $item): array => [
                        'title' => $item['translation']['title'],
                        'slug' => $item['translation']['slug'],
                        'content_type' => $item['content']['content_type'],
                    ],
                    $parsed,
                ),
            ]);
        }

        $this->requireStepUp($request);
        $created = 0;
        $errors = [];

        foreach ($parsed as $item) {
            try {
                $this->persistCsvItem($item);
                $created++;
            } catch (CmsException $e) {
                $errors[] = (is_string($item['translation']['title'] ?? null) ? $item['translation']['title'] : 'untitled') . ': ' . $e->getMessage();
            }
        }

        return Response::json([
            'status' => 'imported',
            'created' => $created,
            'errors' => $errors,
        ]);
    }

    public function analyzeUpload(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');

        if ($this->importAnalyzer === null) {
            return Response::json(['error' => 'Import analysis is not available'], 501);
        }

        $fileContent = $this->extractFileContent($request, 'import_file')
            ?? $this->extractJsonContent($request);

        if ($fileContent === null) {
            return Response::json(['error' => 'File content is required'], 400);
        }

        $data = json_decode($fileContent, true);

        if (!is_array($data)) {
            return Response::json(['error' => 'Invalid JSON content'], 422);
        }

        /** @var array<string, mixed> $data */
        $analysis = $this->importAnalyzer->analyze($data);

        return Response::json($analysis->toArray());
    }

    public function executeWithOptions(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $rawPolicyValue = $body['duplicate_policy'] ?? null;
        $policyValue = is_string($rawPolicyValue) ? $rawPolicyValue : 'skip';
        $policy = DuplicateResolutionPolicy::tryFrom($policyValue) ?? DuplicateResolutionPolicy::Skip;

        $fileContent = $this->extractFileContent($request, 'import_file')
            ?? $this->extractJsonContent($request);

        if ($fileContent === null) {
            return Response::json(['error' => 'File content is required'], 400);
        }

        if ($this->mediaBundleImporter !== null) {
            try {
                $report = $this->mediaBundleImporter->import($fileContent, $policy, dryRun: false);

                return Response::json([
                    'status' => 'imported',
                    'result' => $report->toArray(),
                ]);
            } catch (CmsException $e) {
                return Response::json(['error' => $e->getMessage()], 422);
            }
        }

        // Fallback to standard JSON import
        try {
            $result = $this->importExport->importBundle($fileContent, dryRun: false);

            return Response::json([
                'status' => 'imported',
                'result' => $result->toArray(),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array{content: array<string, mixed>, translation: array<string, mixed>, blocks: list<array<string, mixed>>} $item
     */
    private function persistMarkdownItem(array $item): void
    {
        $c = $item['content'];
        $t = $item['translation'];
        $contentId = UuidGenerator::v7();
        $rawContentType = $c['content_type'] ?? null;
        $contentTypeStr = is_string($rawContentType) ? $rawContentType : 'page';
        $contentType = ContentType::tryFrom($contentTypeStr) ?? ContentType::Page;

        $rawAuthorId = $c['author_id'] ?? null;
        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: is_string($rawAuthorId) ? $rawAuthorId : 'system',
        );

        $this->contentRepository->save($content);

        $rawLocale = $t['locale'] ?? null;
        $rawTitle = $t['title'] ?? null;
        $rawSlug = $t['slug'] ?? null;
        $rawPath = $t['path'] ?? null;
        $rawBody = $t['body'] ?? null;
        $rawExcerpt = $t['excerpt'] ?? null;
        $rawMetaTitle = $t['meta_title'] ?? null;
        $rawMetaDescription = $t['meta_description'] ?? null;
        $slugSegmentFallback = is_string($rawSlug) ? $rawSlug : 'untitled';
        $translation = ContentTranslation::create(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            locale: is_string($rawLocale) ? $rawLocale : 'en',
            title: is_string($rawTitle) ? $rawTitle : '',
            slugSegment: $slugSegmentFallback,
            path: is_string($rawPath) ? $rawPath : $slugSegmentFallback,
            body: is_string($rawBody) ? $rawBody : '',
            excerpt: is_string($rawExcerpt) ? $rawExcerpt : null,
            metaTitle: is_string($rawMetaTitle) ? $rawMetaTitle : null,
            metaDescription: is_string($rawMetaDescription) ? $rawMetaDescription : null,
        );

        $this->translationRepository->save($translation);
    }

    /**
     * @param array{content: array<string, mixed>, translation: array<string, mixed>} $item
     */
    private function persistCsvItem(array $item): void
    {
        $c = $item['content'];
        $t = $item['translation'];
        $contentId = UuidGenerator::v7();
        $contentType = ContentType::tryFrom(is_string($c['content_type'] ?? null) ? $c['content_type'] : 'page') ?? ContentType::Page;

        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: is_string($c['author_id'] ?? null) ? $c['author_id'] : 'system',
        );

        $this->contentRepository->save($content);

        $translation = ContentTranslation::create(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            locale: is_string($t['locale'] ?? null) ? $t['locale'] : 'en',
            title: is_string($t['title'] ?? null) ? $t['title'] : '',
            slugSegment: is_string($t['slug'] ?? null) ? $t['slug'] : 'untitled',
            path: is_string($t['path'] ?? null) ? $t['path'] : (is_string($t['slug'] ?? null) ? $t['slug'] : 'untitled'),
            body: is_string($t['body'] ?? null) ? $t['body'] : '',
            excerpt: is_string($t['excerpt'] ?? null) ? $t['excerpt'] : null,
            metaTitle: is_string($t['meta_title'] ?? null) ? $t['meta_title'] : null,
            metaDescription: is_string($t['meta_description'] ?? null) ? $t['meta_description'] : null,
        );

        $this->translationRepository->save($translation);
    }

    private function extractJsonContent(ServerRequestInterface $request): ?string
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $content = $body['json_content'] ?? null;

        if (is_string($content) && $content !== '') {
            return $content;
        }

        // Try reading from request body directly
        $rawBody = (string) $request->getBody();

        return $rawBody !== '' ? $rawBody : null;
    }

    private function extractFileContent(ServerRequestInterface $request, string $fieldName): ?string
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $content = $body[$fieldName] ?? null;

        if (is_string($content) && $content !== '') {
            return $content;
        }

        $rawBody = (string) $request->getBody();

        return $rawBody !== '' ? $rawBody : null;
    }
}
