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
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function count;
use function is_string;

/**
 * Admin controller for CMS data import.
 *
 * Supports dry-run validation before committing changes.
 * Execute operations require step-up authentication.
 * Accepts JSON bundles, Markdown (with YAML frontmatter), and CSV formats.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ImportController
{
    use RendersAdminView;

    public function __construct(
        private ImportExportServiceInterface $importExport,
        private GateInterface $gate,
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

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
                $errors[] = ($item['translation']['title'] ?: 'untitled') . ': ' . $e->getMessage();
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

        $importer = new CsvContentImporter();
        $parsed = $importer->parse($csvContent);

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
                $errors[] = ($item['translation']['title'] ?: 'untitled') . ': ' . $e->getMessage();
            }
        }

        return Response::json([
            'status' => 'imported',
            'created' => $created,
            'errors' => $errors,
        ]);
    }

    /**
     * @param array{content: array<string, mixed>, translation: array<string, mixed>, blocks: list<array<string, mixed>>} $item
     */
    private function persistMarkdownItem(array $item): void
    {
        $contentId = UuidGenerator::v7();
        $contentType = ContentType::tryFrom($item['content']['content_type'] ?? 'page') ?? ContentType::Page;

        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $item['content']['author_id'] ?? 'system',
        );

        $this->contentRepository->save($content);

        $translation = ContentTranslation::create(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            locale: $item['translation']['locale'] ?? 'en',
            title: $item['translation']['title'] ?? '',
            slugSegment: $item['translation']['slug'] ?? 'untitled',
            path: $item['translation']['path'] ?? $item['translation']['slug'] ?? 'untitled',
            body: $item['translation']['body'] ?? '',
            excerpt: $item['translation']['excerpt'] ?? null,
            metaTitle: $item['translation']['meta_title'] ?? null,
            metaDescription: $item['translation']['meta_description'] ?? null,
        );

        $this->translationRepository->save($translation);
    }

    /**
     * @param array{content: array<string, mixed>, translation: array<string, mixed>} $item
     */
    private function persistCsvItem(array $item): void
    {
        $contentId = UuidGenerator::v7();
        $contentType = ContentType::tryFrom($item['content']['content_type'] ?? 'page') ?? ContentType::Page;

        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $item['content']['author_id'] ?? 'system',
        );

        $this->contentRepository->save($content);

        $translation = ContentTranslation::create(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            locale: $item['translation']['locale'] ?? 'en',
            title: $item['translation']['title'] ?? '',
            slugSegment: $item['translation']['slug'] ?? 'untitled',
            path: $item['translation']['path'] ?? $item['translation']['slug'] ?? 'untitled',
            body: $item['translation']['body'] ?? '',
            excerpt: $item['translation']['excerpt'] ?? null,
            metaTitle: $item['translation']['meta_title'] ?? null,
            metaDescription: $item['translation']['meta_description'] ?? null,
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
