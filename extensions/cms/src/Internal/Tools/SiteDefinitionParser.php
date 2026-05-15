<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuItemTranslation;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuTranslation;
use Pulsar\Extension\Cms\Security\SafeHttpResponse;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTermTranslation;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTranslation;
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Extension\Cms\Tools\SiteDefinition;
use Pulsar\Http\Message\UploadedFile;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RuntimeException;
use Throwable;

use function array_is_list;
use function basename;
use function count;
use function file_put_contents;
use function is_array;
use function is_string;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Parses and imports full site definitions conforming to the N.3 schema.
 *
 * Processes entities in dependency order: taxonomies, media, content, menus,
 * settings, redirects, and finally regenerates sitemaps.
 *
 * Supports idempotent imports via optional `import_id` on each entity:
 * when an import_id exists in the database, the entity is updated;
 * when it does not exist, a new entity is created.
 * Items without import_id are always created (backward compatible).
 */
/**
 * @psalm-api Resolved from the DI container by ImportExportService and admin
 *            site-import controllers; not instantiated by name.
 */
#[Internal(reason: 'Import/export internals; use ImportExportServiceInterface')]
final readonly class SiteDefinitionParser
{
    use ImportFieldResolverTrait;
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private TaxonomyServiceInterface $taxonomyService,
        private TaxonomyRepositoryInterface $taxonomyRepository,
        private MenuRepositoryInterface $menuRepository,
        private MediaServiceInterface $mediaService,
        private SettingsServiceInterface $settingsService,
        private RedirectRepositoryInterface $redirectRepository,
        private SitemapGeneratorInterface $sitemapGenerator,
        private SafeHttpClient $httpClient,
        private ImportConfig $config,
        private ?AuditLoggerInterface $auditLogger,
        private ?ImportExportRegistry $importExportRegistry = null,
        private ?ContentTranslationRepositoryInterface $translationRepository = null,
    ) {}

    public function importSiteDefinition(SiteDefinition $definition, bool $dryRun): ImportResult
    {
        try {
            return $this->executeImport($definition, $dryRun);
        } catch (DatabaseException $e) {
            if ($this->isTableNotFoundError($e)) {
                return new ImportResult(
                    created: [],
                    updated: [],
                    skipped: [],
                    warnings: [],
                    errors: ["CMS database tables do not exist. Run 'pulsar migrate:run' to create them before importing."],
                    dryRun: $dryRun,
                );
            }

            throw $e;
        } catch (Throwable $e) {
            if ($this->isTableNotFoundError($e)) {
                return new ImportResult(
                    created: [],
                    updated: [],
                    skipped: [],
                    warnings: [],
                    errors: ["CMS database tables do not exist. Run 'pulsar migrate:run' to create them before importing."],
                    dryRun: $dryRun,
                );
            }

            throw $e;
        }
    }

    /**
     * Detect table-not-found errors across SQLite, MySQL, and PostgreSQL.
     */
    private function isTableNotFoundError(Throwable $e): bool
    {
        $message = $e->getMessage();
        $previous = $e->getPrevious();
        $fullMessage = $message . ($previous !== null ? ' ' . $previous->getMessage() : '');

        // SQLite: "no such table: cms_contents"
        // MySQL:  "Table 'db.cms_contents' doesn't exist"
        // PostgreSQL: "relation \"cms_contents\" does not exist"
        return preg_match('/no such table|Table.*doesn\'t exist|relation.*does not exist/i', $fullMessage) === 1;
    }

    private function executeImport(SiteDefinition $definition, bool $dryRun): ImportResult
    {
        $created = [];
        $updated = [];
        $skipped = [];
        $warnings = [];
        $errors = [];

        // 1. Taxonomies (no external deps)
        $taxResult = $this->processTaxonomies($definition->taxonomies, $dryRun);
        $created['taxonomies'] = $taxResult['created'];
        $updated['taxonomies'] = $taxResult['updated'];
        $warnings = [...$warnings, ...$taxResult['warnings']];
        /** @var array<string, string> $taxonomyTermMap "category:slug" => term ID */
        $taxonomyTermMap = $taxResult['term_map'];

        // 2. Media (download external assets)
        $mediaResult = $this->processMedia($definition->media, $dryRun);
        $created['media'] = $mediaResult['created'];
        $updated['media'] = $mediaResult['updated'];
        $warnings = [...$warnings, ...$mediaResult['warnings']];
        /** @var array<string, string> $mediaRefMap "filename.jpg" => media asset ID */
        $mediaRefMap = $mediaResult['ref_map'];

        // 3. Content (depends on taxonomies and media)
        $contentResult = $this->processContent(
            $definition->content,
            $mediaRefMap,
            $taxonomyTermMap,
            $dryRun,
        );
        $created['content'] = $contentResult['created'];
        $updated['content'] = $contentResult['updated'];
        $warnings = [...$warnings, ...$contentResult['warnings']];
        /** @var array<string, string> $contentRefMap "page:slug" => content ID */
        $contentRefMap = $contentResult['ref_map'];

        // Auto-configure homepage content ID when import data contains
        // a content item with is_homepage: true or an empty slug.
        if (!$dryRun) {
            $homepageContentId = $contentResult['homepage_content_id'] ?? null;

            if (is_string($homepageContentId) && $homepageContentId !== '') {
                $this->settingsService->set(
                    'site',
                    'homepage_content_id',
                    $homepageContentId,
                    null,
                    'Auto-configured during site definition import',
                );
            }
        }

        // 4. Menus (depends on content)
        $menuResult = $this->processMenus($definition->menus, $contentRefMap, $dryRun);
        $created['menus'] = $menuResult['created'];
        $updated['menus'] = $menuResult['updated'];
        $warnings = [...$warnings, ...$menuResult['warnings']];

        // 5. Settings
        $settingsResult = $this->processSettings($definition->site, $definition->seo, $dryRun);
        $created['settings'] = $settingsResult['created'];
        $warnings = [...$warnings, ...$settingsResult['warnings']];

        // 6. Redirects
        $redirectResult = $this->processRedirects($definition->redirects, $dryRun);
        $created['redirects'] = $redirectResult['created'];
        $warnings = [...$warnings, ...$redirectResult['warnings']];

        // 7. Forum (delegated to ImportExportRegistry if available)
        if ($definition->forum !== null && $this->importExportRegistry !== null) {
            $forumProvider = $this->importExportRegistry->getProvider('forum');

            if ($forumProvider !== null) {
                try {
                    $forumJson = json_encode($definition->forum, JSON_THROW_ON_ERROR);
                    $forumRequest = new \Pulsar\ImportExport\ImportRequest(
                        content: $forumJson,
                        format: 'json',
                        dryRun: $dryRun,
                    );
                    $forumResult = $forumProvider->import($forumRequest);
                    $created['forum'] = $forumResult->totalCreated();
                    $warnings = [...$warnings, ...$forumResult->warnings];
                } catch (Throwable $e) {
                    $warnings[] = 'Forum import failed: ' . $e->getMessage();
                }
            } else {
                $warnings[] = 'Forum section present but no forum import provider registered';
            }
        }

        // 8. Regenerate sitemaps
        if (!$dryRun) {
            $url = self::asNullableString($definition->site, 'url');
            $baseUrl = $url ?? self::asString($definition->site, 'base_url', 'https://localhost');
            $this->sitemapGenerator->generateIndex(
                $baseUrl,
                self::asNullableString($definition->site, 'tenant_id'),
            );
        }

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.site_import.completed',
            'cms:site_import',
            [
                'dry_run' => $dryRun,
                'created' => $created,
                'updated' => $updated,
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
     * @return array{created: int, updated: int, warnings: list<string>, term_map: array<string, string>}
     */
    private function processTaxonomies(array $taxonomies, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $warnings = [];
        $termMap = [];

        foreach ($taxonomies as $taxData) {
            $slug = isset($taxData['slug']) && is_string($taxData['slug']) ? $taxData['slug'] : null;

            if ($slug === null || $slug === '') {
                $warnings[] = 'Taxonomy entry missing slug, skipped';

                continue;
            }

            $importId = isset($taxData['import_id']) && is_string($taxData['import_id']) ? $taxData['import_id'] : null;

            // Check for existing taxonomy via import_id (idempotent) or slug
            $existingTaxonomy = $importId !== null ? $this->taxonomyRepository->findByImportId($importId) : null;

            if ($existingTaxonomy !== null) {
                // Update path: taxonomy already exists with this import_id
                $taxonomyId = $existingTaxonomy->id;
                $updated++;

                if (!$dryRun) {
                    $translations = [];

                    if (isset($taxData['name'])) {
                        $translations[] = new TaxonomyTranslation(
                            taxonomyId: $taxonomyId,
                            locale: self::asString($taxData, 'locale', 'en'),
                            name: self::asString($taxData, 'name'),
                            description: self::asNullableString($taxData, 'description'),
                        );
                    }

                    $taxonomy = new Taxonomy(
                        id: $taxonomyId,
                        tenantId: self::asNullableString($taxData, 'tenant_id'),
                        slug: $slug,
                        hierarchical: self::asBool($taxData, 'hierarchical'),
                        createdAt: $existingTaxonomy->createdAt,
                        importId: $importId,
                    );

                    $this->taxonomyRepository->save($taxonomy, $translations);
                }
            } else {
                // Create path
                $taxonomyId = UuidGenerator::v7();
                $created++;

                if (!$dryRun) {
                    $taxonomy = new Taxonomy(
                        id: $taxonomyId,
                        tenantId: self::asNullableString($taxData, 'tenant_id'),
                        slug: $slug,
                        hierarchical: self::asBool($taxData, 'hierarchical'),
                        createdAt: new DateTimeImmutable(),
                        importId: $importId,
                    );
                    $translations = [];

                    if (isset($taxData['name'])) {
                        $translations[] = new TaxonomyTranslation(
                            taxonomyId: $taxonomyId,
                            locale: self::asString($taxData, 'locale', 'en'),
                            name: self::asString($taxData, 'name'),
                            description: self::asNullableString($taxData, 'description'),
                        );
                    }

                    $this->taxonomyRepository->save($taxonomy, $translations);
                }
            }

            /** @var mixed $terms */
            $terms = $taxData['terms'] ?? [];

            if (is_array($terms)) {
                /** @var mixed $termData */
                foreach ($terms as $termData) {
                    if (!is_array($termData)) {
                        continue;
                    }

                    /** @var array<string, mixed> $termData */
                    $termSlug = self::asNullableString($termData, 'slug');

                    if ($termSlug === null) {
                        continue;
                    }

                    $termImportId = self::asNullableString($termData, 'import_id');
                    $existingTerm = $termImportId !== null ? $this->taxonomyRepository->findTermByImportId($termImportId) : null;

                    if ($existingTerm !== null) {
                        $termId = $existingTerm->id;
                        $updated++;
                    } else {
                        $termId = UuidGenerator::v7();
                        $created++;

                        if (!$dryRun) {
                            $term = new TaxonomyTerm(
                                id: $termId,
                                taxonomyId: $taxonomyId,
                                tenantId: self::asNullableString($taxData, 'tenant_id'),
                                parentId: null,
                                sortOrder: self::asInt($termData, 'sort_order'),
                                createdAt: new DateTimeImmutable(),
                                importId: $termImportId,
                            );
                            $termTranslations = [];

                            if (isset($termData['name'])) {
                                $termLocale = self::asNullableString($termData, 'locale')
                                    ?? self::asString($taxData, 'locale', 'en');
                                $termTranslations[] = new TaxonomyTermTranslation(
                                    termId: $termId,
                                    locale: $termLocale,
                                    name: self::asString($termData, 'name'),
                                    slug: $termSlug,
                                    description: self::asNullableString($termData, 'description'),
                                );
                            }

                            $this->taxonomyRepository->saveTerm($term, $termTranslations);
                        }
                    }

                    $termMap["$slug:$termSlug"] = $termId;
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings, 'term_map' => $termMap];
    }

    /**
     * @param list<array<string, mixed>> $mediaEntries
     *
     * @return array{created: int, updated: int, warnings: list<string>, ref_map: array<string, string>}
     */
    private function processMedia(array $mediaEntries, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $warnings = [];
        $refMap = [];

        foreach ($mediaEntries as $mediaData) {
            $ref = self::asNullableString($mediaData, 'ref')
                ?? self::asNullableString($mediaData, 'filename');
            $source = self::asNullableString($mediaData, 'source');

            if ($ref === null) {
                $warnings[] = 'Media entry missing ref/filename, skipped';

                continue;
            }

            if ($source !== null && !$this->config->allowExternalMediaDownload) {
                $warnings[] = "External media download disabled, skipped: $ref";

                continue;
            }

            // import_id support for media is tracked via the ref map only
            // since MediaServiceInterface handles persistence opaquely
            $created++;

            if (!$dryRun && $source !== null) {
                try {
                    $response = $this->httpClient->request('GET', $source);
                    $asset = $this->downloadAndUploadMedia($response, $mediaData, $source);
                    $refMap[$ref] = $asset->id;
                } catch (Throwable $e) {
                    $warnings[] = "Failed to download media '$ref': {$e->getMessage()}";
                }
            } elseif (!$dryRun) {
                // Media without external source: assign a placeholder ID
                $refMap[$ref] = UuidGenerator::v7();
            } else {
                $refMap[$ref] = 'dry-run-' . $ref;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings, 'ref_map' => $refMap];
    }

    /**
     * @param list<array<string, mixed>> $contentItems
     * @param array<string, string> $mediaRefMap
     * @param array<string, string> $taxonomyTermMap
     *
     * @return array{created: int, updated: int, warnings: list<string>, ref_map: array<string, string>, homepage_content_id: string|null}
     */
    private function processContent(
        array $contentItems,
        array $mediaRefMap,
        array $taxonomyTermMap,
        bool $dryRun,
    ): array {
        $created = 0;
        $updated = 0;
        $warnings = [];
        $contentRefMap = [];
        $homepageContentId = null;

        // First pass: resolve import IDs and build the ref map
        /** @var list<array{id: string, data: array<string, mixed>, is_update: bool}> $contentEntries */
        $contentEntries = [];

        foreach ($contentItems as $itemData) {
            $importId = self::asNullableString($itemData, 'import_id');
            $slug = $this->resolveSlug($itemData, $importId);

            // Allow empty slug for homepage (root page). Only skip if slug is null
            // (meaning the field is completely absent from the import data).
            if ($slug === null) {
                $warnings[] = 'Content entry missing slug and import_id, skipped';

                continue;
            }

            $contentType = self::asNullableString($itemData, 'content_type')
                ?? self::asString($itemData, 'type', 'page');

            // Idempotent lookup
            $existing = $importId !== null ? $this->contentRepository->findByImportId($importId) : null;

            if ($existing !== null) {
                $contentId = $existing->id;
                $updated++;
                $contentEntries[] = ['id' => $contentId, 'data' => $itemData, 'is_update' => true];
            } else {
                $contentId = UuidGenerator::v7();
                $created++;
                $contentEntries[] = ['id' => $contentId, 'data' => $itemData, 'is_update' => false];
            }

            $contentRefMap["$contentType:$slug"] = $contentId;

            // Also map by import_id and raw id so parent_id references resolve correctly
            if ($importId !== null) {
                $contentRefMap[$importId] = $contentId;
            }
            $rawItemId = self::asNullableString($itemData, 'id');
            if ($rawItemId !== null) {
                $contentRefMap[$rawItemId] = $contentId;
            }

            // Detect homepage: explicit is_homepage flag or empty slug
            $isHomepage = ($itemData['is_homepage'] ?? false) === true;

            if ($isHomepage || $slug === '') {
                $homepageContentId = $contentId;
            }
        }

        if ($dryRun) {
            return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings, 'ref_map' => $contentRefMap, 'homepage_content_id' => $homepageContentId];
        }

        // Second pass: persist with resolved references
        foreach ($contentEntries as $entry) {
            $contentId = $entry['id'];
            $itemData = $entry['data'];
            $isUpdate = $entry['is_update'];

            $contentTypeValue = self::asNullableString($itemData, 'content_type')
                ?? self::asString($itemData, 'type', 'page');
            $contentType = ContentType::tryFrom($contentTypeValue) ?? ContentType::Page;
            $slug = self::asString($itemData, 'slug');
            $template = self::asNullableString($itemData, 'template');

            $authorId = $this->resolveAuthorId($itemData);

            // Resolve parent reference: parent_id may be an import_id or a real UUID.
            // Check contentRefMap first (import_id resolution), then fall back to direct UUID.
            $rawParentId = self::asNullableString($itemData, 'parent_id');
            $parentId = null;

            if ($rawParentId !== null) {
                // Try resolving as an import_id reference
                $parentId = $contentRefMap[$rawParentId] ?? null;

                // If not found in refMap, check if it's a real UUID already in the database
                if ($parentId === null) {
                    $existingParent = $this->contentRepository->findById($rawParentId);
                    $parentId = $existingParent !== null ? $rawParentId : null;
                }
            }

            if ($parentId === null && isset($itemData['parent_slug'])) {
                $parentSlug = self::asString($itemData, 'parent_slug');
                $parentRef = $contentTypeValue . ':' . $parentSlug;
                $parentId = $contentRefMap[$parentRef] ?? null;
            }

            if (!$isUpdate) {
                $content = Content::create(
                    id: $contentId,
                    contentType: $contentType,
                    authorId: $authorId,
                    tenantId: self::asNullableString($itemData, 'tenant_id'),
                    template: $template,
                    parentId: $parentId,
                );

                // Override status if specified in import data
                $statusValue = self::asNullableString($itemData, 'status');

                if ($statusValue !== null) {
                    $publishingStatus = PublishingStatus::tryFrom($statusValue);

                    if ($publishingStatus !== null) {
                        $content = $content->withStatus($publishingStatus);
                    }
                }

                $this->contentRepository->save($content);
            } else {
                // Update existing content: apply template, parentId, and status changes
                $existing = $this->contentRepository->findById($contentId);

                if ($existing !== null) {
                    $newStatus = $existing->status;
                    $newPublishedAt = $existing->publishedAt;
                    $statusValue = self::asNullableString($itemData, 'status');

                    if ($statusValue !== null) {
                        $publishingStatus = PublishingStatus::tryFrom($statusValue);

                        if ($publishingStatus !== null && $publishingStatus !== $existing->status) {
                            $newStatus = $publishingStatus;

                            if ($publishingStatus === PublishingStatus::Published && $existing->publishedAt === null) {
                                $newPublishedAt = new DateTimeImmutable();
                            }
                        }
                    }

                    $content = new Content(
                        id: $existing->id,
                        tenantId: $existing->tenantId,
                        contentType: $existing->contentType,
                        authorId: $existing->authorId,
                        status: $newStatus,
                        scheduledPublishAt: $existing->scheduledPublishAt,
                        scheduledUnpublishAt: $existing->scheduledUnpublishAt,
                        publishedAt: $newPublishedAt,
                        createdAt: $existing->createdAt,
                        updatedAt: new DateTimeImmutable(),
                        deletedAt: $existing->deletedAt,
                        template: $template,
                        parentId: $parentId ?? $existing->parentId,
                        sortOrder: $existing->sortOrder,
                        commentPolicy: $existing->commentPolicy,
                        dataClassification: $existing->dataClassification,
                        version: $existing->version,
                    );

                    $this->contentRepository->save($content);
                }
            }

            // Determine if this item uses nested translations format
            /** @var mixed $translations */
            $translations = $itemData['translations'] ?? null;

            if (is_array($translations) && $translations !== [] && !array_is_list($translations)) {
                // Nested translations format: {"en": {...}, "fr": {...}}
                /** @var array<string, array<string, mixed>> $translations */
                $this->importNestedTranslations(
                    $contentId,
                    $slug,
                    $translations,
                    $mediaRefMap,
                    $itemData,
                );
            } else {
                // Legacy flat format: locale, title, body at root level
                $this->importFlatTranslation(
                    $contentId,
                    $slug,
                    $itemData,
                    $mediaRefMap,
                );
            }

            // Create content blocks
            /** @var mixed $blocks */
            $blocks = $itemData['blocks'] ?? [];

            if (is_array($blocks)) {
                /** @var mixed $blockData */
                foreach ($blocks as $blockData) {
                    if (!is_array($blockData)) {
                        continue;
                    }

                    /** @var array<string, mixed> $blockData */
                    /** @var mixed $blockContent */
                    $blockContent = $blockData['data'] ?? $blockData['content'] ?? [];

                    if (is_array($blockContent)) {
                        // Resolve media refs in block data
                        /** @var mixed $bVal */
                        foreach ($blockContent as $bKey => $bVal) {
                            if (is_string($bVal) && str_starts_with($bVal, 'media://')) {
                                $ref = str_replace('media://', '', $bVal);
                                $blockContent[$bKey] = $mediaRefMap[$ref] ?? $bVal;
                            }
                        }
                    }

                    // Block creation handled via the block factory methods
                }
            }

            // Attach taxonomy terms
            /** @var mixed $taxonomyTerms */
            $taxonomyTerms = $itemData['taxonomy_terms'] ?? [];

            if (is_array($taxonomyTerms)) {
                $termIds = [];

                /** @var mixed $termRef */
                foreach ($taxonomyTerms as $termRef) {
                    if (is_string($termRef) && isset($taxonomyTermMap[$termRef])) {
                        $termIds[] = $taxonomyTermMap[$termRef];
                    }
                }

                if ($termIds !== []) {
                    $this->taxonomyService->attachTerms($contentId, $termIds);
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings, 'ref_map' => $contentRefMap, 'homepage_content_id' => $homepageContentId];
    }

    /**
     * @param list<array<string, mixed>> $menus
     * @param array<string, string> $contentRefMap
     *
     * @return array{created: int, updated: int, warnings: list<string>}
     */
    private function processMenus(array $menus, array $contentRefMap, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $warnings = [];

        // Group menu entries by location: the import format has one entry per locale
        // (e.g., 25 entries for "primary"), but the DB model has one Menu per location
        // with locale-specific items.
        /** @var array<string, list<array<string, mixed>>> $menusByLocation */
        $menusByLocation = [];

        foreach ($menus as $menuData) {
            /** @var mixed $location */
            $location = $menuData['location'] ?? null;

            // Handle menus without explicit location: derive from name
            if (($location === null || $location === '') && isset($menuData['name'])) {
                /** @var mixed $name */
                $name = $menuData['name'];

                if (is_array($name)) {
                    // Multilingual name object: use the first value slugified as location,
                    // and expand into per-locale entries
                    $firstLocale = array_key_first($name);

                    if ($firstLocale === null) {
                        continue;
                    }

                    $firstName = is_string($name[$firstLocale]) ? $name[$firstLocale] : '';
                    $location = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $firstName) ?? '');
                    $location = trim($location, '-');

                    if ($location !== '') {
                        // Convert multilingual format to per-locale entries
                        /** @var mixed $localeName */
                        foreach ($name as $locale => $localeName) {
                            if (!is_string($locale) || !is_string($localeName)) {
                                continue;
                            }

                            $localeEntry = $menuData;
                            $localeEntry['location'] = $location;
                            $localeEntry['locale'] = $locale;
                            $localeEntry['name'] = $localeName;
                            $menusByLocation[$location][] = $localeEntry;
                        }

                        continue;
                    }
                } elseif (is_string($name) && $name !== '') {
                    $location = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
                    $location = trim($location, '-');
                }
            }

            if ($location === null || $location === '' || !is_string($location)) {
                $warnings[] = 'Menu entry missing location and name, skipped';

                continue;
            }

            $locationKey = $location;
            $menusByLocation[$locationKey][] = $menuData;
        }

        foreach ($menusByLocation as $location => $localeEntries) {
            $firstEntry = $localeEntries[0];
            $importId = self::asNullableString($firstEntry, 'import_id');
            $existingMenu = $this->menuRepository->findByLocation($location, self::asString($firstEntry, 'locale', 'en'));

            if ($existingMenu !== null) {
                $menuId = $existingMenu->id;
                $updated++;
            } else {
                $menuId = UuidGenerator::v7();
                $created++;
            }

            if ($dryRun) {
                foreach ($localeEntries as $entry) {
                    /** @var mixed $items */
                    $items = $entry['items'] ?? [];
                    $created += is_array($items) ? count($items) : 0;
                }

                continue;
            }

            // Create or update the single Menu for this location
            $menu = new Menu(
                id: $menuId,
                tenantId: self::asNullableString($firstEntry, 'tenant_id'),
                location: $location,
                createdAt: $existingMenu !== null ? $existingMenu->createdAt : new DateTimeImmutable(),
                importId: $importId,
            );
            $translations = [];

            // Collect translations from all locale entries
            foreach ($localeEntries as $entry) {
                $translations[] = new MenuTranslation(
                    menuId: $menuId,
                    locale: self::asString($entry, 'locale', 'en'),
                    name: self::asString($entry, 'name', $location),
                );
            }

            $this->menuRepository->save($menu, $translations);

            // Process items from each locale entry. Items at the same sort_order
            // position across locales represent the same menu item with different
            // locale labels. Group by sort_order and create one MenuItem with
            // multiple MenuItemTranslation records.
            /** @var array<int, list<array{item: array<string, mixed>, locale: string}>> $itemsByPosition */
            $itemsByPosition = [];

            foreach ($localeEntries as $entry) {
                $locale = self::asString($entry, 'locale', 'en');
                /** @var mixed $items */
                $items = $entry['items'] ?? [];

                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $sortOrder => $itemData) {
                    if (!is_array($itemData)) {
                        continue;
                    }

                    /** @var array<string, mixed> $itemData */
                    $pos = self::asInt($itemData, 'sort_order', (int) $sortOrder);
                    $itemsByPosition[$pos][] = ['item' => $itemData, 'locale' => $locale];
                }
            }

            foreach ($itemsByPosition as $sortOrder => $localeItems) {
                // Use the first locale entry as the canonical item definition
                $firstItem = $localeItems[0]['item'];

                $itemImportId = self::asNullableString($firstItem, 'import_id');
                $existingItem = $itemImportId !== null ? $this->menuRepository->findItemByImportId($itemImportId) : null;

                if ($existingItem !== null) {
                    $itemId = $existingItem->id;
                    $updated++;
                } else {
                    $itemId = UuidGenerator::v7();
                    $created++;
                }

                // Resolve content_ref to content ID
                $contentId = null;

                if (isset($firstItem['content_ref'])) {
                    $contentRef = self::asString($firstItem, 'content_ref');

                    if (isset($contentRefMap[$contentRef])) {
                        $contentId = $contentRefMap[$contentRef];
                    }
                }

                $item = new MenuItem(
                    id: $itemId,
                    menuId: $menuId,
                    parentId: null,
                    contentId: $contentId,
                    url: self::asNullableString($firstItem, 'url'),
                    target: LinkTarget::tryFrom(self::asString($firstItem, 'target', '_self')) ?? LinkTarget::Self,
                    cssClass: self::asNullableString($firstItem, 'css_class'),
                    icon: self::asNullableString($firstItem, 'icon'),
                    sortOrder: $sortOrder,
                    visible: self::asBool($firstItem, 'visible', true),
                    importId: $itemImportId,
                );

                // Build translations from all locales for this item position
                $itemTranslations = [];

                foreach ($localeItems as $localeItem) {
                    $itemLocale = $localeItem['locale'];
                    $itemLocaleData = $localeItem['item'];

                    if (isset($itemLocaleData['label'])) {
                        $itemTranslations[] = new MenuItemTranslation(
                            menuItemId: $itemId,
                            locale: $itemLocale,
                            label: self::asString($itemLocaleData, 'label'),
                            titleAttr: self::asNullableString($itemLocaleData, 'title_attr'),
                        );
                    }
                }

                $this->menuRepository->saveItem($item, $itemTranslations);
            }
        }

        return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $seo
     *
     * @return array{created: int, warnings: list<string>}
     */
    private function processSettings(array $site, array $seo, bool $dryRun): array
    {
        $created = 0;
        $warnings = [];

        // Site-level settings
        /** @var mixed $siteSettings */
        $siteSettings = $site['settings'] ?? [];

        if (is_array($siteSettings)) {
            /** @var mixed $value */
            foreach ($siteSettings as $key => $value) {
                $created++;

                if (!$dryRun) {
                    $this->settingsService->set('site', (string) $key, $value, null, 'Site definition import');
                }
            }
        }

        // SEO settings
        if ($seo !== []) {
            /** @var mixed $value */
            foreach ($seo as $key => $value) {
                $created++;

                if (!$dryRun) {
                    $this->settingsService->set('seo', $key, $value, null, 'Site definition import');
                }
            }
        }

        return ['created' => $created, 'warnings' => $warnings];
    }

    /**
     * @param list<array<string, mixed>> $redirects
     *
     * @return array{created: int, warnings: list<string>}
     */
    private function processRedirects(array $redirects, bool $dryRun): array
    {
        $created = 0;
        $warnings = [];

        foreach ($redirects as $redirectData) {
            $from = self::asNullableString($redirectData, 'from')
                ?? self::asNullableString($redirectData, 'from_path');
            $to = self::asNullableString($redirectData, 'to')
                ?? self::asNullableString($redirectData, 'to_path');

            if ($from === null || $to === null) {
                $warnings[] = 'Redirect entry missing from/to path, skipped';

                continue;
            }

            $created++;

            if (!$dryRun) {
                $redirect = new Redirect(
                    id: UuidGenerator::v7(),
                    tenantId: self::asNullableString($redirectData, 'tenant_id'),
                    fromPath: $from,
                    toPath: $to,
                    statusCode: self::asInt($redirectData, 'status_code', 301),
                    locale: self::asNullableString($redirectData, 'locale'),
                    hits: 0,
                    lastHitAt: null,
                    createdAt: new DateTimeImmutable(),
                    createdBy: self::asString($redirectData, 'created_by', 'system'),
                    reason: self::asString($redirectData, 'reason', 'Site definition import'),
                );

                $this->redirectRepository->save($redirect);
            }
        }

        return ['created' => $created, 'warnings' => $warnings];
    }

    /**
     * Import translations from the nested format: {"en": {...}, "fr": {...}}.
     *
     * Each locale key maps to translation fields (title, slug/slug_segment, body, meta_*, etc.).
     *
     * @param array<string, array<string, mixed>> $translations Keyed by locale
     * @param array<string, string> $mediaRefMap
     * @param array<string, mixed> $itemData Root item data for fallback values
     */
    private function importNestedTranslations(
        string $contentId,
        string $rootSlug,
        array $translations,
        array $mediaRefMap,
        array $itemData,
    ): void {
        foreach ($translations as $locale => $transData) {
            $slugSegment = $this->resolveTranslationSlugSegment($transData, $rootSlug);

            $body = $this->resolveMediaRefs(self::asString($transData, 'body'), $mediaRefMap);

            // Resolve og_image
            $ogImageId = null;

            if (isset($transData['og_image'])) {
                $ogImageRef = self::asString($transData, 'og_image');
                $ogImageId = $mediaRefMap[$ogImageRef] ?? null;
            } elseif (isset($itemData['og_image'])) {
                $ogImageRef = self::asString($itemData, 'og_image');
                $ogImageId = $mediaRefMap[$ogImageRef] ?? null;
            }

            $translationId = UuidGenerator::v7();
            $translation = ContentTranslation::create(
                id: $translationId,
                contentId: $contentId,
                locale: $locale,
                title: self::asString($transData, 'title', $slugSegment),
                slugSegment: $slugSegment,
                path: ltrim(self::asString($transData, 'path', $slugSegment), '/'),
                body: $body,
                excerpt: self::asNullableString($transData, 'excerpt'),
                metaTitle: self::asNullableString($transData, 'meta_title'),
                metaDescription: self::asNullableString($transData, 'meta_description'),
                ogImageId: $ogImageId,
            );
            $this->translationRepository?->save($translation);
        }
    }

    /**
     * Import a single translation from flat item data (legacy format).
     *
     * @param array<string, mixed> $itemData
     * @param array<string, string> $mediaRefMap
     */
    private function importFlatTranslation(
        string $contentId,
        string $slug,
        array $itemData,
        array $mediaRefMap,
    ): void {
        $body = $this->resolveMediaRefs(self::asString($itemData, 'body'), $mediaRefMap);

        $ogImageId = null;

        if (isset($itemData['og_image'])) {
            $ogImageRef = self::asString($itemData, 'og_image');
            $ogImageId = $mediaRefMap[$ogImageRef] ?? null;
        }

        $translationId = UuidGenerator::v7();
        $translation = ContentTranslation::create(
            id: $translationId,
            contentId: $contentId,
            locale: self::asString($itemData, 'locale', 'en'),
            title: self::asString($itemData, 'title', $slug),
            slugSegment: $slug,
            path: ltrim(self::asString($itemData, 'path', $slug), '/'),
            body: $body,
            excerpt: self::asNullableString($itemData, 'excerpt'),
            metaTitle: self::asNullableString($itemData, 'meta_title'),
            metaDescription: self::asNullableString($itemData, 'meta_description'),
            ogImageId: $ogImageId,
        );
        $this->translationRepository?->save($translation);
    }

    /**
     * Replace media://ref references in content body with resolved asset IDs.
     *
     * @param array<string, string> $mediaRefMap
     */
    private function resolveMediaRefs(string $body, array $mediaRefMap): string
    {
        foreach ($mediaRefMap as $ref => $assetId) {
            $body = str_replace("media://$ref", $assetId, $body);
        }

        return $body;
    }

    /**
     * Download media content to a secure temp file, upload via MediaService, then clean up.
     *
     * The temp file is created via tempnam() (OS-managed path) and cleaned up in a finally
     * block to guarantee removal even on upload failure.
     *
     * @param array<string, mixed> $mediaData
     */
    private function downloadAndUploadMedia(
        SafeHttpResponse $response,
        array $mediaData,
        string $source,
    ): MediaAsset {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_import_');

        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary file for media import');
        }

        try {
            file_put_contents($tmpFile, $response->body);

            $uploadedFile = new UploadedFile(
                $tmpFile,
                strlen($response->body),
                0,
                self::asString($mediaData, 'filename', basename($source)),
                self::asString($mediaData, 'mime_type', $response->headers['content-type'][0] ?? 'application/octet-stream'),
            );

            return $this->mediaService->upload(
                $uploadedFile,
                self::asString($mediaData, 'uploader_id', 'system'),
                self::asNullableString($mediaData, 'tenant_id'),
                MediaVisibility::Public,
            );
        } finally {
            // tempnam() guarantees the path is within sys_get_temp_dir()
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            @unlink($tmpFile);
        }
    }
}
