<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\ContentTypeRegistry;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuItemTranslation;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuTranslation;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTermTranslation;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTranslation;
use Pulsar\Extension\Cms\Tools\DuplicateResolutionPolicy;
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_key_first;
use function bin2hex;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function preg_replace;
use function sodium_crypto_generichash;
use function strlen;
use function strtolower;
use function trim;

use const JSON_ERROR_NONE;

/**
 * Parses and imports CMS data from exported JSON bundles.
 *
 * Supports content with blocks, translations, taxonomy hierarchy preservation,
 * duplicate resolution policies, and locale filtering.
 */
#[Internal(reason: 'Import/export internals; use ImportExportServiceInterface')]
/**
 * @psalm-api Resolved from the DI container by MediaBundleImporter and
 *            ImportExportService; not instantiated by name.
 */
final readonly class ImportParser
{
    use ImportFieldResolverTrait;
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ContentBlockRepositoryInterface $blockRepository,
        private TaxonomyRepositoryInterface $taxonomyRepository,
        private MenuRepositoryInterface $menuRepository,
        private SettingsServiceInterface $settingsService,
        private ImportConfig $config,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    public function importBundle(string $jsonContent, bool $dryRun): ImportResult
    {
        if (strlen($jsonContent) > $this->config->maxImportSizeBytes) {
            return new ImportResult(
                created: [],
                updated: [],
                skipped: [],
                warnings: [],
                errors: ['Import file exceeds maximum size of ' . $this->config->maxImportSizeBytes . ' bytes'],
                dryRun: $dryRun,
            );
        }

        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return new ImportResult(
                created: [],
                updated: [],
                skipped: [],
                warnings: [],
                errors: ['Invalid JSON: ' . json_last_error_msg()],
                dryRun: $dryRun,
            );
        }

        /** @var array<string, int> $created */
        $created = [];
        /** @var array<string, int> $updated */
        $updated = [];
        /** @var array<string, int> $skipped */
        $skipped = [];
        /** @var list<string> $warnings */
        $warnings = [];
        /** @var list<string> $errors */
        $errors = [];

        if (isset($data['taxonomies']) && is_array($data['taxonomies'])) {
            /** @var list<array<string, mixed>> $taxonomyData */
            $taxonomyData = $data['taxonomies'];
            $result = $this->processTaxonomies($taxonomyData, $dryRun);
            $created['taxonomies'] = $result['created'];
            $updated['taxonomies'] = $result['updated'];
            $skipped['taxonomies'] = $result['skipped'];
            $warnings = [...$warnings, ...$result['warnings']];
        }

        /** @var array<string, string> $contentRefMap Maps content slug to content ID */
        $contentRefMap = [];

        if (isset($data['content']) && is_array($data['content'])) {
            /** @var list<array<string, mixed>> $contentData */
            $contentData = $data['content'];
            $result = $this->processContent($contentData, $dryRun);
            $created['content'] = $result['created'];
            $updated['content'] = $result['updated'];
            $skipped['content'] = $result['skipped'];
            $warnings = [...$warnings, ...$result['warnings']];
            $contentRefMap = $result['ref_map'];
        }

        if (isset($data['menus']) && is_array($data['menus'])) {
            /** @var list<array<string, mixed>> $menuData */
            $menuData = $data['menus'];
            $result = $this->processMenus($menuData, $contentRefMap, $dryRun);
            $created['menus'] = $result['created'];
            $updated['menus'] = $result['updated'];
            $skipped['menus'] = $result['skipped'];
            $warnings = [...$warnings, ...$result['warnings']];
        }

        if (isset($data['settings']) && is_array($data['settings'])) {
            /** @var array<string, array<string, mixed>> $settingsData */
            $settingsData = $data['settings'];
            $settingsResult = $this->processSettings($settingsData, $dryRun);
            $created['settings'] = $settingsResult['created'];
            $updated['settings'] = $settingsResult['updated'];
            $warnings = [...$warnings, ...$settingsResult['warnings']];
        }

        $bundleHash = bin2hex(sodium_crypto_generichash($jsonContent));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.import.completed',
            'cms:import',
            [
                'bundle_hash' => $bundleHash,
                'dry_run' => $dryRun,
                'created' => $created,
                'updated' => $updated,
                'duplicate_policy' => $this->config->duplicatePolicy->value,
            ],
        );

        return new ImportResult(
            created: $created,
            updated: $updated,
            skipped: $skipped,
            warnings: $warnings,
            errors: $errors,
            dryRun: $dryRun,
        );
    }

    /**
     * @param list<array<string, mixed>> $taxonomies
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>}
     */
    private function processTaxonomies(array $taxonomies, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($taxonomies as $taxData) {
            $slug = isset($taxData['slug']) && is_string($taxData['slug']) ? $taxData['slug'] : null;

            if ($slug === null || $slug === '') {
                $warnings[] = 'Taxonomy entry missing slug, skipped';
                $skipped++;

                continue;
            }

            $tenantId = isset($taxData['tenant_id']) && is_string($taxData['tenant_id']) ? $taxData['tenant_id'] : null;
            $existing = $this->taxonomyRepository->findBySlug($slug, $tenantId);

            if ($existing !== null) {
                $updated++;

                if (!$dryRun && isset($taxData['terms']) && is_array($taxData['terms'])) {
                    /** @var list<array<string, mixed>> $terms */
                    $terms = $taxData['terms'];
                    $this->importTaxonomyTerms($existing->id, $terms, $tenantId);
                }
            } else {
                $created++;

                if (!$dryRun) {
                    $taxonomyId = UuidGenerator::v7();
                    $taxonomy = new Taxonomy(
                        id: $taxonomyId,
                        tenantId: $tenantId,
                        slug: $slug,
                        hierarchical: is_bool($taxData['hierarchical'] ?? null) ? $taxData['hierarchical'] : false,
                        createdAt: new DateTimeImmutable(),
                    );
                    $translations = [];

                    if (isset($taxData['name']) && is_string($taxData['name'])) {
                        $translations[] = new TaxonomyTranslation(
                            taxonomyId: $taxonomyId,
                            locale: isset($taxData['locale']) && is_string($taxData['locale']) ? $taxData['locale'] : 'en',
                            name: $taxData['name'],
                            description: isset($taxData['description']) && is_string($taxData['description']) ? $taxData['description'] : null,
                        );
                    }

                    $this->taxonomyRepository->save($taxonomy, $translations);

                    if (isset($taxData['terms']) && is_array($taxData['terms'])) {
                        /** @var list<array<string, mixed>> $terms */
                        $terms = $taxData['terms'];
                        $this->importTaxonomyTerms($taxonomyId, $terms, $tenantId);
                    }
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Import taxonomy terms with hierarchy preservation.
     *
     * Uses a two-pass approach: first pass creates all terms with parentId=null,
     * second pass updates parentId using slug-based lookup from the import data.
     *
     * @param list<array<string, mixed>> $terms
     */
    private function importTaxonomyTerms(string $taxonomyId, array $terms, ?string $tenantId): void
    {
        /** @var array<string, string> $slugToIdMap old slug → new UUID */
        $slugToIdMap = [];

        // Pass 1: create all terms without hierarchy
        foreach ($terms as $termData) {
            $termId = UuidGenerator::v7();
            $nameStr = isset($termData['name']) && is_string($termData['name']) ? $termData['name'] : 'unnamed';
            $termSlug = isset($termData['slug']) && is_string($termData['slug']) ? $termData['slug'] : $this->slugify($nameStr);
            $slugToIdMap[$termSlug] = $termId;

            $term = new TaxonomyTerm(
                id: $termId,
                taxonomyId: $taxonomyId,
                tenantId: $tenantId,
                parentId: null,
                sortOrder: isset($termData['sort_order']) && (is_int($termData['sort_order']) || is_string($termData['sort_order'])) ? (int) $termData['sort_order'] : 0,
                createdAt: new DateTimeImmutable(),
            );
            $translations = [];

            if ($nameStr !== 'unnamed') {
                $localeStr = isset($termData['locale']) && is_string($termData['locale']) ? $termData['locale'] : 'en';
                $descStr = isset($termData['description']) && is_string($termData['description']) ? $termData['description'] : null;
                $translations[] = new TaxonomyTermTranslation(
                    termId: $termId,
                    locale: $localeStr,
                    name: $nameStr,
                    slug: $termSlug,
                    description: $descStr,
                );
            }

            $this->taxonomyRepository->saveTerm($term, $translations);
        }

        // Pass 2: set parent IDs for hierarchical terms
        foreach ($terms as $termData) {
            $parentSlug = isset($termData['parent_slug']) && is_string($termData['parent_slug']) ? $termData['parent_slug'] : null;

            if ($parentSlug === null || $parentSlug === '') {
                continue;
            }

            $nameStr = isset($termData['name']) && is_string($termData['name']) ? $termData['name'] : 'unnamed';
            $termSlug = isset($termData['slug']) && is_string($termData['slug']) ? $termData['slug'] : $this->slugify($nameStr);
            $childId = $slugToIdMap[$termSlug] ?? null;
            $parentId = $slugToIdMap[$parentSlug] ?? null;

            if ($childId !== null && $parentId !== null) {
                $this->taxonomyRepository->updateTermParent($childId, $parentId);
            }
        }
    }

    /**
     * Process content items with translations, blocks, and duplicate resolution.
     *
     * Supports the `translations` key for multilingual content:
     * ```json
     * {
     *   "content_type": "page",
     *   "translations": {
     *     "en": {"title": "...", "slug_segment": "...", ...},
     *     "fr": {"title": "...", "slug_segment": "...", ...}
     *   },
     *   "blocks": [
     *     {"blockType": "heading", "sortOrder": 0, "data": {"level": 1, "text": "..."}}
     *   ]
     * }
     * ```
     *
     * Also supports flat format for backward compatibility:
     * ```json
     * {"content_type": "page", "locale": "en", "slug": "about", ...}
     * ```
     *
     * @param list<array<string, mixed>> $contentItems
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>, ref_map: array<string, string>}
     */
    private function processContent(array $contentItems, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];
        /** @var array<string, string> $contentRefMap Maps "slug" => content ID for newly created items */
        $contentRefMap = [];
        $policy = $this->config->duplicatePolicy;

        foreach ($contentItems as $itemData) {
            // Determine content type (built-in enum or custom registered type)
            $contentTypeSlug = isset($itemData['content_type']) && is_string($itemData['content_type'])
                ? $itemData['content_type']
                : 'page';

            if (!ContentTypeRegistry::isValid($contentTypeSlug)) {
                $warnings[] = "Unknown content type '$contentTypeSlug', skipped";
                $skipped++;

                continue;
            }

            // Multi-locale format: translations map
            if (isset($itemData['translations']) && is_array($itemData['translations'])) {
                $result = $this->processMultilocaleContent($itemData, $dryRun, $policy);
                $created += $result['created'];
                $updated += $result['updated'];
                $skipped += $result['skipped'];
                $warnings = [...$warnings, ...$result['warnings']];

                continue;
            }

            // Flat format (backward compatible)
            $slug = $this->resolveSlug($itemData);
            $locale = isset($itemData['locale']) && is_string($itemData['locale']) ? $itemData['locale'] : 'en';

            if ($slug === null || $slug === '') {
                $warnings[] = 'Content entry missing slug, skipped';
                $skipped++;

                continue;
            }

            // Locale filtering
            if ($this->config->allowedLocales !== null && !in_array($locale, $this->config->allowedLocales, true)) {
                $warnings[] = "Content '$slug' has unsupported locale '$locale', skipped";
                $skipped++;

                continue;
            }

            $tenantId = isset($itemData['tenant_id']) && is_string($itemData['tenant_id']) ? $itemData['tenant_id'] : null;
            $existing = $this->contentRepository->findByPath($locale, $slug, $tenantId);

            if ($existing !== null) {
                $result = $this->handleDuplicate($existing, $itemData, $locale, $slug, $dryRun, $policy);
                $created += $result['created'];
                $updated += $result['updated'];
                $skipped += $result['skipped'];
                $warnings = [...$warnings, ...$result['warnings']];
            } else {
                $created++;

                if (!$dryRun) {
                    $newId = $this->createContentItem($itemData, $locale, $slug);
                    $contentRefMap[$slug] = $newId;
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings, 'ref_map' => $contentRefMap];
    }

    /**
     * Process a content item with the `translations` key (multi-locale format).
     *
     * @param array<string, mixed> $itemData
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>}
     */
    private function processMultilocaleContent(array $itemData, bool $dryRun, DuplicateResolutionPolicy $policy): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        /** @var array<string, array<string, mixed>> $translations */
        $translations = $itemData['translations'];

        // Use the first available locale to check for existing content
        $firstLocale = array_key_first($translations);

        if (!is_string($firstLocale)) {
            $warnings[] = 'Content has empty translations, skipped';
            $skipped++;

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
        }

        $firstTranslation = $translations[$firstLocale];
        $importId = isset($itemData['import_id']) && is_string($itemData['import_id']) ? $itemData['import_id'] : null;
        $slug = $this->resolveSlug($firstTranslation, $importId);

        // Also check 'path' as a last resort before import_id
        if ($slug === null && is_string($firstTranslation['path'] ?? null)) {
            $slug = $firstTranslation['path'];
        }

        // Allow empty slug_segment for homepage (root page).
        // Only skip if slug is null (field completely absent).
        if ($slug === null) {
            $warnings[] = 'Content translation missing slug_segment and import_id, skipped';
            $skipped++;

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
        }

        $tenantId = isset($itemData['tenant_id']) && is_string($itemData['tenant_id']) ? $itemData['tenant_id'] : null;
        $existing = $this->contentRepository->findByPath($firstLocale, $slug, $tenantId);

        if ($existing !== null) {
            $existingId = $existing->id;
            $resolvedFirstLocale = $firstLocale;

            // Content already exists: apply duplicate resolution policy
            match ($policy) {
                DuplicateResolutionPolicy::Skip => $skipped++,
                DuplicateResolutionPolicy::Replace,
                DuplicateResolutionPolicy::Merge => (function () use (&$updated, $existingId, $translations, $itemData, $resolvedFirstLocale, $dryRun): void {
                    $updated++;

                    if (!$dryRun) {
                        $this->updateTranslations($existingId, $translations);

                        /** @var list<array<string, mixed>> $blocks */
                        $blocks = isset($itemData['blocks']) && is_array($itemData['blocks']) ? $itemData['blocks'] : [];
                        $this->importBlocks($existingId, $blocks, $resolvedFirstLocale, $dryRun);
                    }
                })(),
                DuplicateResolutionPolicy::ImportAsNew => (function () use (&$created, $translations, $itemData, $dryRun): void {
                    $created++;

                    if (!$dryRun) {
                        $this->createMultilocaleContentItem($itemData, $translations, appendSlug: true);
                    }
                })(),
            };

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
        }

        // New content: create with all translations
        $created++;

        if (!$dryRun) {
            $this->createMultilocaleContentItem($itemData, $translations, appendSlug: false);
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Create a content item with multiple locale translations and blocks.
     *
     * @param array<string, mixed> $itemData
     * @param array<string, array<string, mixed>> $translations
     */
    private function createMultilocaleContentItem(array $itemData, array $translations, bool $appendSlug): void
    {
        $contentId = UuidGenerator::v7();
        $contentTypeSlug = isset($itemData['content_type']) && is_string($itemData['content_type'])
            ? $itemData['content_type']
            : 'page';
        $contentType = ContentType::tryFrom($contentTypeSlug) ?? ContentType::Page;

        $status = isset($itemData['status']) && is_string($itemData['status']) ? $itemData['status'] : 'published';
        $authorId = $this->resolveAuthorId($itemData);
        $tenantId = isset($itemData['tenant_id']) && is_string($itemData['tenant_id']) ? $itemData['tenant_id'] : null;
        $template = isset($itemData['template']) && is_string($itemData['template']) ? $itemData['template'] : null;

        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $authorId,
            tenantId: $tenantId,
            template: $template,
        );

        if ($status === 'published') {
            $content = $content->publish();
        }

        $this->contentRepository->save($content);

        foreach ($translations as $locale => $transData) {
            if ($this->config->allowedLocales !== null && !in_array($locale, $this->config->allowedLocales, true)) {
                continue;
            }

            $title = isset($transData['title']) && is_string($transData['title']) ? $transData['title'] : 'Untitled';
            $slugSegment = $this->resolveTranslationSlugSegment(
                $transData,
                is_string($transData['path'] ?? null) ? $transData['path'] : $this->slugify($title),
            );

            if ($appendSlug) {
                $slugSegment .= '-imported';
            }

            $path = ltrim(isset($transData['path']) && is_string($transData['path']) ? $transData['path'] : $slugSegment, '/');

            $translation = ContentTranslation::create(
                id: UuidGenerator::v7(),
                contentId: $contentId,
                locale: (string) $locale,
                title: $title,
                slugSegment: $slugSegment,
                path: $path,
                body: isset($transData['body']) && is_string($transData['body']) ? $transData['body'] : '',
                excerpt: isset($transData['excerpt']) && is_string($transData['excerpt']) ? $transData['excerpt'] : null,
                metaTitle: isset($transData['meta_title']) && is_string($transData['meta_title']) ? $transData['meta_title'] : null,
                metaDescription: isset($transData['meta_description']) && is_string($transData['meta_description']) ? $transData['meta_description'] : null,
            );

            $this->translationRepository->save($translation);
        }

        // Import blocks (use first locale as default for blocks)
        $firstLocaleKey = array_key_first($translations);
        $firstLocale = is_string($firstLocaleKey) ? $firstLocaleKey : 'en';

        if (isset($itemData['blocks']) && is_array($itemData['blocks'])) {
            /** @var list<array<string, mixed>> $blocks */
            $blocks = $itemData['blocks'];
            $this->importBlocks($contentId, $blocks, $firstLocale, false);
        }
    }

    /**
     * Update translations for an existing content item.
     *
     * @param array<string, array<string, mixed>> $translations
     */
    private function updateTranslations(string $contentId, array $translations): void
    {
        foreach ($translations as $locale => $transData) {
            $localeStr = $locale;

            if ($this->config->allowedLocales !== null && !in_array($localeStr, $this->config->allowedLocales, true)) {
                continue;
            }

            $existingTranslation = $this->translationRepository->findByContentAndLocale($contentId, $localeStr);
            $title = isset($transData['title']) && is_string($transData['title']) ? $transData['title'] : null;
            $slugSeg = isset($transData['slug_segment']) && is_string($transData['slug_segment']) ? $transData['slug_segment'] : null;
            $path = isset($transData['path']) && is_string($transData['path']) ? $transData['path'] : null;
            $body = isset($transData['body']) && is_string($transData['body']) ? $transData['body'] : null;
            $excerpt = isset($transData['excerpt']) && is_string($transData['excerpt']) ? $transData['excerpt'] : null;
            $metaTitle = isset($transData['meta_title']) && is_string($transData['meta_title']) ? $transData['meta_title'] : null;
            $metaDesc = isset($transData['meta_description']) && is_string($transData['meta_description']) ? $transData['meta_description'] : null;

            if ($existingTranslation !== null) {
                // Update existing translation
                $updated = ContentTranslation::create(
                    id: $existingTranslation->id,
                    contentId: $contentId,
                    locale: $localeStr,
                    title: $title ?? $existingTranslation->title,
                    slugSegment: $slugSeg ?? $existingTranslation->slugSegment,
                    path: $path ?? $existingTranslation->path,
                    body: $body ?? $existingTranslation->body,
                    excerpt: $excerpt ?? $existingTranslation->excerpt,
                    metaTitle: $metaTitle ?? $existingTranslation->metaTitle,
                    metaDescription: $metaDesc ?? $existingTranslation->metaDescription,
                );

                $this->translationRepository->save($updated);
            } else {
                // Create new translation for existing content
                $translation = ContentTranslation::create(
                    id: UuidGenerator::v7(),
                    contentId: $contentId,
                    locale: $localeStr,
                    title: $title ?? 'Untitled',
                    slugSegment: $slugSeg ?? $path ?? 'untitled',
                    path: $path ?? $slugSeg ?? 'untitled',
                    body: $body ?? '',
                    excerpt: $excerpt,
                    metaTitle: $metaTitle,
                    metaDescription: $metaDesc,
                );

                $this->translationRepository->save($translation);
            }
        }
    }

    /**
     * Handle a duplicate content item according to the duplicate resolution policy.
     *
     * @param array<string, mixed> $itemData
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>}
     */
    private function handleDuplicate(
        Content $existing,
        array $itemData,
        string $locale,
        string $slug,
        bool $dryRun,
        DuplicateResolutionPolicy $policy,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];
        $contentId = $existing->id;

        match ($policy) {
            DuplicateResolutionPolicy::Skip => $skipped++,
            DuplicateResolutionPolicy::Replace => (function () use (&$updated, $contentId, $itemData, $locale, $dryRun): void {
                $updated++;

                if (!$dryRun) {
                    $this->updateSingleTranslation($contentId, $locale, $itemData);
                    /** @var list<array<string, mixed>> $blocks */
                    $blocks = isset($itemData['blocks']) && is_array($itemData['blocks']) ? $itemData['blocks'] : [];
                    $this->importBlocks($contentId, $blocks, $locale, false);
                }
            })(),
            DuplicateResolutionPolicy::Merge => (function () use (&$updated, $contentId, $itemData, $locale, $dryRun): void {
                $updated++;

                if (!$dryRun) {
                    $this->mergeSingleTranslation($contentId, $locale, $itemData);
                    // Only import blocks if provided and content has none
                    if (isset($itemData['blocks']) && is_array($itemData['blocks'])) {
                        $existingBlocks = $this->blockRepository->findByContentAndLocale($contentId, $locale);

                        if ($existingBlocks === []) {
                            /** @var list<array<string, mixed>> $blocks */
                            $blocks = $itemData['blocks'];
                            $this->importBlocks($contentId, $blocks, $locale, false);
                        }
                    }
                }
            })(),
            DuplicateResolutionPolicy::ImportAsNew => (function () use (&$created, $itemData, $locale, $slug, $dryRun): void {
                $created++;

                if (!$dryRun) {
                    $newSlug = $slug . '-imported';
                    $modifiedData = $itemData;
                    $modifiedData['slug'] = $newSlug;
                    $this->createContentItem($modifiedData, $locale, $newSlug);
                }
            })(),
        };

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Create a content item from flat (single-locale) format.
     *
     * @param array<string, mixed> $itemData
     */
    private function createContentItem(array $itemData, string $locale, string $slug): string
    {
        $contentId = UuidGenerator::v7();
        $contentTypeSlug = isset($itemData['content_type']) && is_string($itemData['content_type'])
            ? $itemData['content_type']
            : 'page';
        $contentType = ContentType::tryFrom($contentTypeSlug) ?? ContentType::Page;
        $authorId = $this->resolveAuthorId($itemData);
        $tenantId = isset($itemData['tenant_id']) && is_string($itemData['tenant_id']) ? $itemData['tenant_id'] : null;
        $template = isset($itemData['template']) && is_string($itemData['template']) ? $itemData['template'] : null;

        $content = Content::create(
            id: $contentId,
            contentType: $contentType,
            authorId: $authorId,
            tenantId: $tenantId,
            template: $template,
        );

        $status = isset($itemData['status']) && is_string($itemData['status']) ? $itemData['status'] : 'published';

        if ($status === 'published') {
            $content = $content->publish();
        }

        $this->contentRepository->save($content);

        // Create translation
        $title = isset($itemData['title']) && is_string($itemData['title']) ? $itemData['title'] : 'Untitled';
        $path = ltrim(isset($itemData['path']) && is_string($itemData['path']) ? $itemData['path'] : $slug, '/');
        $body = isset($itemData['body']) && is_string($itemData['body']) ? $itemData['body'] : '';

        $translation = ContentTranslation::create(
            id: UuidGenerator::v7(),
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slug,
            path: $path,
            body: $body,
            excerpt: isset($itemData['excerpt']) && is_string($itemData['excerpt']) ? $itemData['excerpt'] : null,
            metaTitle: isset($itemData['meta_title']) && is_string($itemData['meta_title']) ? $itemData['meta_title'] : null,
            metaDescription: isset($itemData['meta_description']) && is_string($itemData['meta_description']) ? $itemData['meta_description'] : null,
        );

        $this->translationRepository->save($translation);

        // Import blocks
        if (isset($itemData['blocks']) && is_array($itemData['blocks'])) {
            /** @var list<array<string, mixed>> $blocks */
            $blocks = $itemData['blocks'];
            $this->importBlocks($contentId, $blocks, $locale, false);
        }

        return $contentId;
    }

    /**
     * Update a single translation for an existing content item (Replace policy).
     *
     * @param array<string, mixed> $itemData
     */
    private function updateSingleTranslation(string $contentId, string $locale, array $itemData): void
    {
        $existing = $this->translationRepository->findByContentAndLocale($contentId, $locale);
        $title = isset($itemData['title']) && is_string($itemData['title']) ? $itemData['title'] : 'Untitled';
        $slugSeg = isset($itemData['slug']) && is_string($itemData['slug'])
            ? $itemData['slug']
            : (isset($itemData['slug_segment']) && is_string($itemData['slug_segment']) ? $itemData['slug_segment'] : 'untitled');
        $path = isset($itemData['path']) && is_string($itemData['path']) ? $itemData['path'] : $slugSeg;

        $translation = ContentTranslation::create(
            id: $existing !== null ? $existing->id : UuidGenerator::v7(),
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slugSeg,
            path: $path,
            body: isset($itemData['body']) && is_string($itemData['body']) ? $itemData['body'] : '',
            excerpt: isset($itemData['excerpt']) && is_string($itemData['excerpt']) ? $itemData['excerpt'] : null,
            metaTitle: isset($itemData['meta_title']) && is_string($itemData['meta_title']) ? $itemData['meta_title'] : null,
            metaDescription: isset($itemData['meta_description']) && is_string($itemData['meta_description']) ? $itemData['meta_description'] : null,
        );

        $this->translationRepository->save($translation);
    }

    /**
     * Merge a single translation (only update empty/null fields on existing).
     *
     * @param array<string, mixed> $itemData
     */
    private function mergeSingleTranslation(string $contentId, string $locale, array $itemData): void
    {
        $existing = $this->translationRepository->findByContentAndLocale($contentId, $locale);

        if ($existing === null) {
            $this->updateSingleTranslation($contentId, $locale, $itemData);

            return;
        }

        $importTitle = isset($itemData['title']) && is_string($itemData['title']) ? $itemData['title'] : null;
        $importBody = isset($itemData['body']) && is_string($itemData['body']) ? $itemData['body'] : null;
        $importExcerpt = isset($itemData['excerpt']) && is_string($itemData['excerpt']) ? $itemData['excerpt'] : null;
        $importMetaTitle = isset($itemData['meta_title']) && is_string($itemData['meta_title']) ? $itemData['meta_title'] : null;
        $importMetaDesc = isset($itemData['meta_description']) && is_string($itemData['meta_description']) ? $itemData['meta_description'] : null;

        $translation = ContentTranslation::create(
            id: $existing->id,
            contentId: $contentId,
            locale: $locale,
            title: $existing->title !== '' ? $existing->title : ($importTitle ?? $existing->title),
            slugSegment: $existing->slugSegment,
            path: $existing->path,
            body: $existing->body !== '' ? $existing->body : ($importBody ?? $existing->body),
            excerpt: $existing->excerpt ?? $importExcerpt,
            metaTitle: $existing->metaTitle ?? $importMetaTitle,
            metaDescription: $existing->metaDescription ?? $importMetaDesc,
        );

        $this->translationRepository->save($translation);
    }

    /**
     * Import content blocks from the blocks array.
     *
     * Each block has: blockType (string), sortOrder (int), data (object).
     * Deletes existing blocks first (for Replace policy), then creates new ones.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function importBlocks(string $contentId, array $blocks, string $locale, bool $dryRun): void
    {
        if ($blocks === [] || $dryRun) {
            return;
        }

        // Clear existing blocks for this content+locale before importing
        $this->blockRepository->deleteByContentAndLocale($contentId, $locale);

        $newBlocks = [];

        foreach ($blocks as $blockData) {
            $blockType = $blockData['blockType'] ?? $blockData['block_type'] ?? null;

            if (!is_string($blockType) || $blockType === '') {
                continue;
            }

            $rawOrder = $blockData['sortOrder'] ?? $blockData['sort_order'] ?? 0;
            $sortOrder = is_int($rawOrder) ? $rawOrder : (is_string($rawOrder) ? (int) $rawOrder : 0);

            /** @var array<string, mixed> $data */
            $data = $blockData['data'] ?? [];

            $now = new DateTimeImmutable();
            $newBlocks[] = new ContentBlock(
                id: UuidGenerator::v7(),
                contentId: $contentId,
                locale: $locale,
                blockType: $blockType,
                sortOrder: $sortOrder,
                data: $data,
                createdAt: $now,
                updatedAt: $now,
            );
        }

        if ($newBlocks !== []) {
            $this->blockRepository->saveAll($newBlocks);
        }
    }

    /**
     * Process menu entries from the import data.
     *
     * Supports both flat format (single locale per menu) and the multilingual
     * translations format (one menu entry with an embedded locale map).
     *
     * Flat format:
     *   {"location": "header", "locale": "en", "name": "Main Nav", "items": [...]}
     *
     * Multilingual format:
     *   {"location": "header", "translations": {"en": {"name": "Main Nav"}, "fr": {"name": "Nav"}}, "items": [...]}
     *
     * Accepts 'slug' as a fallback alias for 'location'.
     *
     * @param list<array<string, mixed>> $menus
     * @param array<string, string> $contentRefMap Maps content slug to content ID for linking menu items
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>}
     */
    private function processMenus(array $menus, array $contentRefMap, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];
        $policy = $this->config->duplicatePolicy;

        foreach ($menus as $menuData) {
            $location = isset($menuData['location']) && is_string($menuData['location'])
                ? $menuData['location']
                : (isset($menuData['slug']) && is_string($menuData['slug']) ? $menuData['slug'] : null);

            if ($location === null || $location === '') {
                $warnings[] = 'Menu entry missing location, skipped';
                $skipped++;

                continue;
            }

            // Multilingual format: one menu, multiple locales via translations map
            if (isset($menuData['translations']) && is_array($menuData['translations'])) {
                $result = $this->processMultilocaleMenu($menuData, $location, $contentRefMap, $dryRun, $policy);
                $created += $result['created'];
                $updated += $result['updated'];
                $skipped += $result['skipped'];
                $warnings = [...$warnings, ...$result['warnings']];

                continue;
            }

            // Flat format: single locale per menu entry
            $locale = isset($menuData['locale']) && is_string($menuData['locale']) ? $menuData['locale'] : 'en';

            if ($this->config->allowedLocales !== null && !in_array($locale, $this->config->allowedLocales, true)) {
                $warnings[] = "Menu at location '$location' has unsupported locale '$locale', skipped";
                $skipped++;

                continue;
            }

            $tenantId = isset($menuData['tenant_id']) && is_string($menuData['tenant_id']) ? $menuData['tenant_id'] : null;
            $existing = $this->menuRepository->findByLocation($location, $locale, $tenantId);

            if ($existing !== null) {
                match ($policy) {
                    DuplicateResolutionPolicy::Skip => $skipped++,
                    DuplicateResolutionPolicy::Replace => (function () use (&$updated, $existing, $menuData, $locale, $contentRefMap, $dryRun): void {
                        $updated++;

                        if (!$dryRun && isset($menuData['items']) && is_array($menuData['items'])) {
                            /** @var list<array<string, mixed>> $items */
                            $items = $menuData['items'];
                            $this->importMenuItems($existing->id, $items, $locale, $contentRefMap);
                        }
                    })(),
                    DuplicateResolutionPolicy::Merge,
                    DuplicateResolutionPolicy::ImportAsNew => $updated++,
                };
            } else {
                $created++;

                if (!$dryRun) {
                    $menuId = UuidGenerator::v7();
                    $menu = new Menu(
                        id: $menuId,
                        tenantId: $tenantId,
                        location: $location,
                        createdAt: new DateTimeImmutable(),
                    );
                    $translations = [];

                    if (isset($menuData['name']) && is_string($menuData['name'])) {
                        $translations[] = new MenuTranslation(
                            menuId: $menuId,
                            locale: $locale,
                            name: $menuData['name'],
                        );
                    }

                    $this->menuRepository->save($menu, $translations);

                    if (isset($menuData['items']) && is_array($menuData['items'])) {
                        /** @var list<array<string, mixed>> $items */
                        $items = $menuData['items'];
                        $this->importMenuItems($menuId, $items, $locale, $contentRefMap);
                    }
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Process a menu with the multilingual translations format.
     *
     * Creates one menu and one MenuTranslation per locale in the translations map.
     *
     * @param array<string, mixed>  $menuData
     * @param array<string, string> $contentRefMap
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>}
     */
    private function processMultilocaleMenu(
        array $menuData,
        string $location,
        array $contentRefMap,
        bool $dryRun,
        DuplicateResolutionPolicy $policy,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        /** @var array<string, array<string, mixed>> $translations */
        $translations = $menuData['translations'];

        // Use the first locale to check for an existing menu
        $firstLocale = array_key_first($translations);

        if (!is_string($firstLocale)) {
            $warnings[] = "Menu at location '$location' has empty translations, skipped";
            $skipped++;

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
        }

        $tenantId = isset($menuData['tenant_id']) && is_string($menuData['tenant_id']) ? $menuData['tenant_id'] : null;
        $existing = $this->menuRepository->findByLocation($location, $firstLocale, $tenantId);

        if ($existing !== null) {
            match ($policy) {
                DuplicateResolutionPolicy::Skip => $skipped++,
                DuplicateResolutionPolicy::Replace => (function () use (&$updated, $existing, $menuData, $firstLocale, $contentRefMap, $dryRun): void {
                    $updated++;

                    if (!$dryRun && isset($menuData['items']) && is_array($menuData['items'])) {
                        /** @var list<array<string, mixed>> $items */
                        $items = $menuData['items'];
                        $this->importMenuItems($existing->id, $items, $firstLocale, $contentRefMap);
                    }
                })(),
                DuplicateResolutionPolicy::Merge,
                DuplicateResolutionPolicy::ImportAsNew => $updated++,
            };

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
        }

        $created++;

        if (!$dryRun) {
            $menuId = UuidGenerator::v7();
            $menu = new Menu(
                id: $menuId,
                tenantId: $tenantId,
                location: $location,
                createdAt: new DateTimeImmutable(),
            );
            $menuTranslations = [];

            foreach ($translations as $locale => $transData) {
                if (!is_string($locale) || !is_array($transData)) {
                    continue;
                }

                if ($this->config->allowedLocales !== null && !in_array($locale, $this->config->allowedLocales, true)) {
                    continue;
                }

                $name = is_string($transData['name'] ?? null) ? $transData['name'] : '';

                if ($name !== '') {
                    $menuTranslations[] = new MenuTranslation(
                        menuId: $menuId,
                        locale: $locale,
                        name: $name,
                    );
                }
            }

            $this->menuRepository->save($menu, $menuTranslations);

            if (isset($menuData['items']) && is_array($menuData['items'])) {
                /** @var list<array<string, mixed>> $items */
                $items = $menuData['items'];
                $this->importMenuItems($menuId, $items, $firstLocale, $contentRefMap);
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Resolve a menu item's content ID, checking the ref map for newly imported content.
     *
     * @param array<string, mixed> $itemData
     * @param array<string, string> $contentRefMap
     */
    private function resolveMenuItemContentId(array $itemData, array $contentRefMap): ?string
    {
        $contentId = isset($itemData['content_id']) && is_string($itemData['content_id']) ? $itemData['content_id'] : null;

        // If the content_id looks like a slug reference, resolve it from the ref map
        if ($contentId !== null && isset($contentRefMap[$contentId])) {
            return $contentRefMap[$contentId];
        }

        // Check content_ref as an explicit slug reference field
        $contentRef = isset($itemData['content_ref']) && is_string($itemData['content_ref']) ? $itemData['content_ref'] : null;

        if ($contentRef !== null && isset($contentRefMap[$contentRef])) {
            return $contentRefMap[$contentRef];
        }

        return $contentId;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $contentRefMap Maps content slug to content ID
     */
    private function importMenuItems(string $menuId, array $items, string $locale, array $contentRefMap = [], ?string $parentId = null): void
    {
        foreach ($items as $sortOrder => $itemData) {
            $itemId = UuidGenerator::v7();
            $targetStr = isset($itemData['target']) && is_string($itemData['target']) ? $itemData['target'] : '_self';

            $item = new MenuItem(
                id: $itemId,
                menuId: $menuId,
                parentId: $parentId,
                contentId: $this->resolveMenuItemContentId($itemData, $contentRefMap),
                url: isset($itemData['url']) && is_string($itemData['url']) ? $itemData['url'] : null,
                target: LinkTarget::tryFrom($targetStr) ?? LinkTarget::Self,
                cssClass: isset($itemData['css_class']) && is_string($itemData['css_class']) ? $itemData['css_class'] : null,
                icon: isset($itemData['icon']) && is_string($itemData['icon']) ? $itemData['icon'] : null,
                sortOrder: isset($itemData['sort_order']) && (is_int($itemData['sort_order']) || is_string($itemData['sort_order']))
                    ? (int) $itemData['sort_order']
                    : $sortOrder,
                visible: is_bool($itemData['visible'] ?? null) ? $itemData['visible'] : true,
            );
            $translations = [];

            if (isset($itemData['label']) && is_string($itemData['label'])) {
                $translations[] = new MenuItemTranslation(
                    menuItemId: $itemId,
                    locale: $locale,
                    label: $itemData['label'],
                    titleAttr: isset($itemData['title_attr']) && is_string($itemData['title_attr']) ? $itemData['title_attr'] : null,
                );
            }

            $this->menuRepository->saveItem($item, $translations);

            // Recursively import nested children
            if (isset($itemData['children']) && is_array($itemData['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $itemData['children'];
                $this->importMenuItems($menuId, $children, $locale, $contentRefMap, $itemId);
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array{created: int, updated: int, warnings: list<string>}
     */
    private function processSettings(array $settings, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $warnings = [];

        // Detect flat array format: if any value is not an array, treat
        // the entire payload as a single "general" group. This handles
        // imports that use {"key": "value"} instead of {"group": {"key": "value"}}.
        $isFlat = false;

        foreach ($settings as $value) {
            if (!is_array($value)) {
                $isFlat = true;
                break;
            }
        }

        if ($isFlat) {
            $settings = ['general' => $settings];
        }

        foreach ($settings as $group => $keys) {
            if (!is_array($keys)) {
                $warnings[] = "Settings group '$group' has invalid format, skipped";

                continue;
            }

            foreach ($keys as $key => $value) {
                $existing = $this->settingsService->get($group, (string) $key);

                if ($existing !== null) {
                    $updated++;
                } else {
                    $created++;
                }

                if (!$dryRun) {
                    $this->settingsService->set($group, (string) $key, $value, null, 'Imported from bundle');
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings];
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = (string) preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = (string) preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }
}
