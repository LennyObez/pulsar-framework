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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
                /** @var mixed $rawTitle */
                $rawTitle = $item['translation']['title'] ?? null;
                $title = is_string($rawTitle) ? $rawTitle : 'untitled';
                $errors[] = $title . ': ' . $e->getMessage();
            }
        }

        return Response::json([
            'status' => 'imported',
            'created' => $created,
            'errors' => $errors,
        ]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
                /** @var mixed $rawTitle */
                $rawTitle = $item['translation']['title'] ?? null;
                $title = is_string($rawTitle) ? $rawTitle : 'untitled';
                $errors[] = $title . ': ' . $e->getMessage();
            }
        }

        return Response::json([
            'status' => 'imported',
            'created' => $created,
            'errors' => $errors,
        ]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function executeWithOptions(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.import');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawPolicyValue */
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
     * @param array{
     *     content: array{content_type?: string, author_id?: string},
     *     translation: array{
     *         locale?: string,
     *         title?: string,
     *         slug?: string,
     *         path?: string,
     *         body?: string,
     *         excerpt?: string|null,
     *         meta_title?: string|null,
     *         meta_description?: string|null,
     *     },
     *     blocks: list<array<string, mixed>>,
     * } $item
     */
    private function persistMarkdownItem(array $item): void
    {
        $c = $item['content'];
        $t = $item['translation'];
        $contentId = UuidGenerator::v7();
        $contentType = ContentType::tryFrom($c['content_type'] ?? 'page') ?? ContentType::Page;

        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $c['author_id'] ?? 'system',
        );

        $this->contentRepository->save($content);

        $slugSegmentFallback = $t['slug'] ?? 'untitled';
        $translation = ContentTranslation::create(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            locale: $t['locale'] ?? 'en',
            title: $t['title'] ?? '',
            slugSegment: $slugSegmentFallback,
            path: $t['path'] ?? $slugSegmentFallback,
            body: $t['body'] ?? '',
            excerpt: $t['excerpt'] ?? null,
            metaTitle: $t['meta_title'] ?? null,
            metaDescription: $t['meta_description'] ?? null,
        );

        $this->translationRepository->save($translation);
    }

    /**
     * @param array{
     *     content: array{content_type?: string, author_id?: string},
     *     translation: array{
     *         locale?: string,
     *         title?: string,
     *         slug?: string,
     *         path?: string,
     *         body?: string,
     *         excerpt?: string|null,
     *         meta_title?: string|null,
     *         meta_description?: string|null,
     *     },
     * } $item
     */
    private function persistCsvItem(array $item): void
    {
        $c = $item['content'];
        $t = $item['translation'];
        $contentId = UuidGenerator::v7();
        $contentType = ContentType::tryFrom($c['content_type'] ?? 'page') ?? ContentType::Page;

        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $c['author_id'] ?? 'system',
        );

        $this->contentRepository->save($content);

        $slugFallback = $t['slug'] ?? 'untitled';
        $translation = ContentTranslation::create(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            locale: $t['locale'] ?? 'en',
            title: $t['title'] ?? '',
            slugSegment: $slugFallback,
            path: $t['path'] ?? $slugFallback,
            body: $t['body'] ?? '',
            excerpt: $t['excerpt'] ?? null,
            metaTitle: $t['meta_title'] ?? null,
            metaDescription: $t['meta_description'] ?? null,
        );

        $this->translationRepository->save($translation);
    }

    private function extractJsonContent(ServerRequestInterface $request): ?string
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $content */
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

        /** @var mixed $content */
        $content = $body[$fieldName] ?? null;

        if (is_string($content) && $content !== '') {
            return $content;
        }

        $rawBody = (string) $request->getBody();

        return $rawBody !== '' ? $rawBody : null;
    }
}
