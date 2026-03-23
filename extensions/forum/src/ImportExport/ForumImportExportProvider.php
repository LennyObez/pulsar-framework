<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\ImportExport;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ExportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportRequest;
use Pulsar\ImportExport\ImportResult;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sodium_crypto_generichash;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;

/**
 * Forum import/export provider.
 *
 * Exports/imports categories, threads (with posts), tags, and badge definitions.
 */
#[Internal(reason: 'Wired in ForumExtension::postBoot()')]
final readonly class ForumImportExportProvider implements ImportExportProviderInterface
{
    private const array SUPPORTED_ENTITY_TYPES = [
        'categories',
        'threads',
        'tags',
    ];

    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private CategoryTranslationRepositoryInterface $categoryTranslationRepository,
        private ThreadRepositoryInterface $threadRepository,
        private TagRepositoryInterface $tagRepository,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'forum';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum';
    }

    #[Override]
    public function supportedFormats(): array
    {
        return ['json'];
    }

    #[Override]
    public function export(ExportRequest $request): ExportResult
    {
        $entityTypes = $request->entityTypes !== []
            ? array_values(array_filter(
                $request->entityTypes,
                fn(string $t) => in_array($t, self::SUPPORTED_ENTITY_TYPES, true),
            ))
            : self::SUPPORTED_ENTITY_TYPES;

        $data = [];
        $warnings = [];

        foreach ($entityTypes as $type) {
            $data[$type] = match ($type) {
                'categories' => $this->exportCategories(),
                'threads' => $this->exportThreads(),
                'tags' => $this->exportTags(),
                default => [],
            };
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $hash = bin2hex(sodium_crypto_generichash($json, '', SODIUM_CRYPTO_GENERICHASH_BYTES));

        return new ExportResult(
            providerName: 'forum',
            data: $data,
            format: $request->format,
            evidenceHash: $hash,
            entityTypes: $entityTypes,
            warnings: $warnings,
        );
    }

    #[Override]
    public function import(ImportRequest $request): ImportResult
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->content, true, 512, JSON_THROW_ON_ERROR);

        $created = [];
        $updated = [];
        $skipped = [];
        $warnings = [];
        $errors = [];

        foreach (self::SUPPORTED_ENTITY_TYPES as $type) {
            if (!array_key_exists($type, $payload) || !is_array($payload[$type])) {
                continue;
            }

            /** @var list<array<string, mixed>> $entityData */
            $entityData = $payload[$type];

            $result = match ($type) {
                'categories' => $this->importCategories($entityData, $request->dryRun),
                'threads' => $this->importThreads($entityData, $request->dryRun),
                'tags' => $this->importTags($entityData, $request->dryRun),
            };

            if ($result['created'] > 0) {
                $created[$type] = $result['created'];
            }

            if ($result['updated'] > 0) {
                $updated[$type] = $result['updated'];
            }

            if ($result['skipped'] > 0) {
                $skipped[$type] = $result['skipped'];
            }

            /** @var list<string> $typeWarnings */
            $typeWarnings = $result['warnings'];
            $warnings = [...$warnings, ...$typeWarnings];

            /** @var list<string> $typeErrors */
            $typeErrors = $result['errors'];
            $errors = [...$errors, ...$typeErrors];
        }

        return new ImportResult(
            providerName: 'forum',
            created: $created,
            updated: $updated,
            skipped: $skipped,
            warnings: $warnings,
            errors: $errors,
            dryRun: $request->dryRun,
        );
    }

    #[Override]
    public function schema(): array
    {
        return [
            'categories' => [
                'id' => 'string (UUIDv7)',
                'slug' => 'string',
                'parent_id' => 'string|null (UUIDv7)',
                'sort_order' => 'int',
                'is_locked' => 'bool',
                'translations' => 'array<locale, {name: string, description: string}>',
            ],
            'threads' => [
                'id' => 'string (UUIDv7)',
                'category_id' => 'string (UUIDv7)',
                'author_id' => 'string (UUIDv7)',
                'title' => 'string',
                'slug' => 'string',
                'type' => 'string (discussion, question, bug_report, feature_request)',
                'status' => 'string (open, closed, locked)',
                'posts' => 'array<Post>',
            ],
            'tags' => [
                'id' => 'string (UUIDv7)',
                'slug' => 'string',
                'name' => 'string',
                'description' => 'string',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportCategories(): array
    {
        $categories = $this->categoryRepository->findRoots();
        $exported = [];

        foreach ($categories as $category) {
            $translations = $this->categoryTranslationRepository->findByCategory($category->id);
            $translationData = [];

            foreach ($translations as $translation) {
                $translationData[$translation->locale] = [
                    'name' => $translation->name,
                    'description' => $translation->description,
                ];
            }

            $entry = [
                'id' => $category->id,
                'slug' => $category->slug,
                'parent_id' => $category->parentId,
                'sort_order' => $category->sortOrder,
                'is_locked' => $category->isLocked,
                'translations' => $translationData,
            ];

            $exported[] = $entry;

            // Include child categories
            $children = $this->categoryRepository->findByParent($category->id);

            foreach ($children as $child) {
                $childTranslations = $this->categoryTranslationRepository->findByCategory($child->id);
                $childTranslationData = [];

                foreach ($childTranslations as $t) {
                    $childTranslationData[$t->locale] = [
                        'name' => $t->name,
                        'description' => $t->description,
                    ];
                }

                $exported[] = [
                    'id' => $child->id,
                    'slug' => $child->slug,
                    'parent_id' => $child->parentId,
                    'sort_order' => $child->sortOrder,
                    'is_locked' => $child->isLocked,
                    'translations' => $childTranslationData,
                ];
            }
        }

        return $exported;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportThreads(): array
    {
        $result = $this->threadRepository->findRecent(page: 1, perPage: 10000);

        $exported = [];

        foreach ($result->items as $thread) {
            $exported[] = [
                'id' => $thread->id,
                'category_id' => $thread->categoryId,
                'author_id' => $thread->authorId,
                'title' => $thread->title,
                'slug' => $thread->slug,
                'type' => $thread->type->value,
                'status' => $thread->status->value,
                'is_pinned' => $thread->isPinned,
                'is_locked' => $thread->isLocked,
                'created_at' => $thread->createdAt->format(DateTimeImmutable::ATOM),
            ];
        }

        return $exported;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportTags(): array
    {
        $tags = $this->tagRepository->findAll();

        return array_map(fn($tag) => [
            'id' => $tag->id,
            'slug' => $tag->slug,
            'name' => $tag->name,
            'description' => $tag->description,
            'usage_count' => $tag->usageCount,
        ], $tags);
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>, errors: list<string>}
     */
    private function importCategories(array $categories, bool $dryRun): array
    {
        $created = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($categories as $entry) {
            if (!isset($entry['slug']) || !is_string($entry['slug'])) {
                $warnings[] = 'Skipping category with missing slug';
                $skipped++;

                continue;
            }

            $existing = $this->categoryRepository->findBySlug($entry['slug']);

            if ($existing !== null) {
                $skipped++;

                continue;
            }

            if (!$dryRun) {
                $rawId = $entry['id'] ?? null;
                $id = is_string($rawId) ? $rawId : bin2hex(random_bytes(16));
                $rawParentId = $entry['parent_id'] ?? null;
                $parentId = is_string($rawParentId) ? $rawParentId : null;
                $rawSortOrder = $entry['sort_order'] ?? null;
                $sortOrder = is_int($rawSortOrder) ? $rawSortOrder : 0;
                $category = \Pulsar\Extension\Forum\Category\Category::create(
                    id: $id,
                    slug: $entry['slug'],
                    parentId: $parentId,
                    sortOrder: $sortOrder,
                );
                $this->categoryRepository->save($category);

                // Import category translations
                if (isset($entry['translations']) && is_array($entry['translations'])) {
                    /** @var array<string, array{name?: string, description?: string}> $translations */
                    $translations = $entry['translations'];

                    foreach ($translations as $locale => $translationData) {
                        if (!is_string($locale) || !is_array($translationData)) {
                            $warnings[] = sprintf(
                                'Skipping invalid translation entry for category "%s"',
                                $entry['slug'],
                            );

                            continue;
                        }

                        $rawName = $translationData['name'] ?? null;
                        $name = is_string($rawName) ? $rawName : '';
                        $rawDescription = $translationData['description'] ?? null;
                        $description = is_string($rawDescription) ? $rawDescription : '';

                        if ($name === '') {
                            $warnings[] = sprintf(
                                'Skipping translation for category "%s" locale "%s": empty name',
                                $entry['slug'],
                                $locale,
                            );

                            continue;
                        }

                        $translation = \Pulsar\Extension\Forum\Category\CategoryTranslation::create(
                            id: bin2hex(random_bytes(16)),
                            categoryId: $id,
                            locale: $locale,
                            name: $name,
                            description: $description,
                        );
                        $this->categoryTranslationRepository->save($translation);
                    }
                }
            }

            $created++;
        }

        return ['created' => $created, 'updated' => 0, 'skipped' => $skipped, 'warnings' => $warnings, 'errors' => []];
    }

    /**
     * @param list<array<string, mixed>> $threads
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>, errors: list<string>}
     */
    private function importThreads(array $threads, bool $dryRun): array
    {
        $created = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($threads as $entry) {
            if (!isset($entry['slug']) || !is_string($entry['slug'])) {
                $warnings[] = 'Skipping thread with missing slug';
                $skipped++;

                continue;
            }

            if (!isset($entry['title']) || !is_string($entry['title'])) {
                $warnings[] = sprintf('Skipping thread "%s" with missing title', $entry['slug']);
                $skipped++;

                continue;
            }

            $existing = $this->threadRepository->findBySlug($entry['slug']);

            if ($existing !== null) {
                $skipped++;

                continue;
            }

            if (!$dryRun) {
                $rawId = $entry['id'] ?? null;
                $threadId = is_string($rawId) ? $rawId : bin2hex(random_bytes(16));
                $rawCategoryId = $entry['category_id'] ?? null;
                $categoryId = is_string($rawCategoryId) ? $rawCategoryId : '';
                $rawAuthorId = $entry['author_id'] ?? null;
                $authorId = is_string($rawAuthorId) ? $rawAuthorId : 'system';
                $rawTypeStr = $entry['type'] ?? null;
                $typeStr = is_string($rawTypeStr) ? $rawTypeStr : 'discussion';
                $threadType = \Pulsar\Extension\Forum\Domain\ThreadType::tryFrom($typeStr) ?? \Pulsar\Extension\Forum\Domain\ThreadType::Discussion;

                $rawIpHash = $entry['ip_hash'] ?? null;
                $ipHash = is_string($rawIpHash) ? $rawIpHash : '';
                $rawUserAgentHash = $entry['user_agent_hash'] ?? null;
                $userAgentHash = is_string($rawUserAgentHash) ? $rawUserAgentHash : '';

                $thread = \Pulsar\Extension\Forum\Thread\Thread::create(
                    id: $threadId,
                    categoryId: $categoryId,
                    authorId: $authorId,
                    title: $entry['title'],
                    slug: $entry['slug'],
                    type: $threadType,
                    ipHash: $ipHash,
                    userAgentHash: $userAgentHash,
                );
                $this->threadRepository->save($thread);
            }

            $created++;
        }

        return ['created' => $created, 'updated' => 0, 'skipped' => $skipped, 'warnings' => $warnings, 'errors' => []];
    }

    /**
     * @param list<array<string, mixed>> $tags
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>, errors: list<string>}
     */
    private function importTags(array $tags, bool $dryRun): array
    {
        $created = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($tags as $entry) {
            if (!isset($entry['slug']) || !is_string($entry['slug'])) {
                $warnings[] = 'Skipping tag with missing slug';
                $skipped++;

                continue;
            }

            $existing = $this->tagRepository->findBySlug($entry['slug']);

            if ($existing !== null) {
                $skipped++;

                continue;
            }

            if (!$dryRun) {
                $rawTagId = $entry['id'] ?? null;
                $tagId = is_string($rawTagId) ? $rawTagId : bin2hex(random_bytes(16));
                $rawTagName = $entry['name'] ?? null;
                $tagName = is_string($rawTagName) ? $rawTagName : $entry['slug'];
                $rawTagDescription = $entry['description'] ?? null;
                $tagDescription = is_string($rawTagDescription) ? $rawTagDescription : '';
                $tag = \Pulsar\Extension\Forum\Tag\Tag::create(
                    id: $tagId,
                    slug: $entry['slug'],
                    name: $tagName,
                    description: $tagDescription,
                );
                $this->tagRepository->save($tag);
            }

            $created++;
        }

        return ['created' => $created, 'updated' => 0, 'skipped' => $skipped, 'warnings' => $warnings, 'errors' => []];
    }
}
