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
use function is_bool;
use function is_int;
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
            $rawUrl = $definition->site['url'] ?? null;
            $rawBaseUrl = $definition->site['base_url'] ?? null;
            $baseUrl = is_string($rawUrl)
                ? $rawUrl
                : (is_string($rawBaseUrl) ? $rawBaseUrl : 'https://localhost');
            $rawTenantId = $definition->site['tenant_id'] ?? null;
            $tenantId = is_string($rawTenantId) ? $rawTenantId : null;
            $this->sitemapGenerator->generateIndex($baseUrl, $tenantId);
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
                            locale: is_string($taxData['locale'] ?? null) ? $taxData['locale'] : 'en',
                            name: is_string($taxData['name']) ? $taxData['name'] : '',
                            description: is_string($taxData['description'] ?? null) ? $taxData['description'] : null,
                        );
                    }

                    $taxonomy = new Taxonomy(
                        id: $taxonomyId,
                        tenantId: is_string($taxData['tenant_id'] ?? null) ? $taxData['tenant_id'] : null,
                        slug: $slug,
                        hierarchical: is_bool($taxData['hierarchical'] ?? null) ? $taxData['hierarchical'] : false,
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
                        tenantId: is_string($taxData['tenant_id'] ?? null) ? $taxData['tenant_id'] : null,
                        slug: $slug,
                        hierarchical: is_bool($taxData['hierarchical'] ?? null) ? $taxData['hierarchical'] : false,
                        createdAt: new DateTimeImmutable(),
                        importId: $importId,
                    );
                    $translations = [];

                    if (isset($taxData['name'])) {
                        $translations[] = new TaxonomyTranslation(
                            taxonomyId: $taxonomyId,
                            locale: is_string($taxData['locale'] ?? null) ? $taxData['locale'] : 'en',
                            name: is_string($taxData['name']) ? $taxData['name'] : '',
                            description: is_string($taxData['description'] ?? null) ? $taxData['description'] : null,
                        );
                    }

                    $this->taxonomyRepository->save($taxonomy, $translations);
                }
            }

            $terms = $taxData['terms'] ?? [];

            if (is_array($terms)) {
                foreach ($terms as $termData) {
                    if (!is_array($termData)) {
                        continue;
                    }

                    /** @var array<string, mixed> $termData */
                    $rawTermSlug = $termData['slug'] ?? null;
                    $termSlug = is_string($rawTermSlug) ? $rawTermSlug : null;

                    if ($termSlug === null) {
                        continue;
                    }

                    $rawTermImportId = $termData['import_id'] ?? null;
                    $termImportId = is_string($rawTermImportId) ? $rawTermImportId : null;
                    $existingTerm = $termImportId !== null ? $this->taxonomyRepository->findTermByImportId($termImportId) : null;

                    if ($existingTerm !== null) {
                        $termId = $existingTerm->id;
                        $updated++;
                    } else {
                        $termId = UuidGenerator::v7();
                        $created++;

                        if (!$dryRun) {
                            $rawTaxTenantId = $taxData['tenant_id'] ?? null;
                            $rawTermSortOrder = $termData['sort_order'] ?? null;
                            $term = new TaxonomyTerm(
                                id: $termId,
                                taxonomyId: $taxonomyId,
                                tenantId: is_string($rawTaxTenantId) ? $rawTaxTenantId : null,
                                parentId: null,
                                sortOrder: is_int($rawTermSortOrder) ? $rawTermSortOrder : 0,
                                createdAt: new DateTimeImmutable(),
                                importId: $termImportId,
                            );
                            $termTranslations = [];

                            if (isset($termData['name'])) {
                                $rawTermLocale = $termData['locale'] ?? null;
                                $rawTaxLocale = $taxData['locale'] ?? null;
                                $termLocale = is_string($rawTermLocale)
                                    ? $rawTermLocale
                                    : (is_string($rawTaxLocale) ? $rawTaxLocale : 'en');
                                $rawTermName = $termData['name'];
                                $rawTermDescription = $termData['description'] ?? null;
                                $termTranslations[] = new TaxonomyTermTranslation(
                                    termId: $termId,
                                    locale: $termLocale,
                                    name: is_string($rawTermName) ? $rawTermName : '',
                                    slug: $termSlug,
                                    description: is_string($rawTermDescription) ? $rawTermDescription : null,
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
            $rawRef = $mediaData['ref'] ?? null;
            $rawFilename = $mediaData['filename'] ?? null;
            $ref = is_string($rawRef)
                ? $rawRef
                : (is_string($rawFilename) ? $rawFilename : null);
            $rawSource = $mediaData['source'] ?? null;
            $source = is_string($rawSource) ? $rawSource : null;

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
            $rawImportId = $itemData['import_id'] ?? null;
            $importId = is_string($rawImportId) ? $rawImportId : null;
            $slug = $this->resolveSlug($itemData, $importId);

            // Allow empty slug for homepage (root page). Only skip if slug is null
            // (meaning the field is completely absent from the import data).
            if ($slug === null) {
                $warnings[] = 'Content entry missing slug and import_id, skipped';

                continue;
            }

            $rawContentType = $itemData['content_type'] ?? null;
            $rawType = $itemData['type'] ?? null;
            $contentType = is_string($rawContentType)
                ? $rawContentType
                : (is_string($rawType) ? $rawType : 'page');

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
            $rawItemId = $itemData['id'] ?? null;
            if (is_string($rawItemId)) {
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

            $rawContentTypeValue = $itemData['content_type'] ?? null;
            $rawTypeValue = $itemData['type'] ?? null;
            $contentTypeValue = is_string($rawContentTypeValue)
                ? $rawContentTypeValue
                : (is_string($rawTypeValue) ? $rawTypeValue : 'page');
            $contentType = ContentType::tryFrom($contentTypeValue) ?? ContentType::Page;
            $rawSlug = $itemData['slug'] ?? null;
            $slug = is_string($rawSlug) ? $rawSlug : '';
            $rawTemplate = $itemData['template'] ?? null;
            $template = is_string($rawTemplate) ? $rawTemplate : null;
            $rawImportId = $itemData['import_id'] ?? null;
            $importId = is_string($rawImportId) ? $rawImportId : null;

            $authorId = $this->resolveAuthorId($itemData);

            // Resolve parent reference: parent_id may be an import_id or a real UUID.
            // Check contentRefMap first (import_id resolution), then fall back to direct UUID.
            $rawParentId = is_string($itemData['parent_id'] ?? null) ? $itemData['parent_id'] : null;
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
                $parentSlug = is_string($itemData['parent_slug']) ? $itemData['parent_slug'] : '';
                $parentRef = $contentTypeValue . ':' . $parentSlug;
                $parentId = $contentRefMap[$parentRef] ?? null;
            }

            if (!$isUpdate) {
                $content = Content::create(
                    id: $contentId,
                    contentType: $contentType,
                    authorId: $authorId,
                    tenantId: is_string($itemData['tenant_id'] ?? null) ? $itemData['tenant_id'] : null,
                    template: $template,
                    parentId: $parentId,
                );

                // Override status if specified in import data
                $statusValue = is_string($itemData['status'] ?? null) ? $itemData['status'] : null;

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
                    $statusValue = is_string($itemData['status'] ?? null) ? $itemData['status'] : null;

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
            $blocks = $itemData['blocks'] ?? [];

            if (is_array($blocks)) {
                foreach ($blocks as $blockData) {
                    if (!is_array($blockData)) {
                        continue;
                    }

                    /** @var array<string, mixed> $blockData */
                    $blockContent = $blockData['data'] ?? $blockData['content'] ?? [];

                    if (is_array($blockContent)) {
                        // Resolve media refs in block data
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
            $taxonomyTerms = $itemData['taxonomy_terms'] ?? [];

            if (is_array($taxonomyTerms)) {
                $termIds = [];

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
            $location = $menuData['location'] ?? null;

            // Handle menus without explicit location: derive from name
            if (($location === null || $location === '') && isset($menuData['name'])) {
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
            $importId = is_string($firstEntry['import_id'] ?? null) ? $firstEntry['import_id'] : null;
            $existingMenu = $this->menuRepository->findByLocation($location, is_string($firstEntry['locale'] ?? null) ? $firstEntry['locale'] : 'en');

            if ($existingMenu !== null) {
                $menuId = $existingMenu->id;
                $updated++;
            } else {
                $menuId = UuidGenerator::v7();
                $created++;
            }

            if ($dryRun) {
                foreach ($localeEntries as $entry) {
                    $items = $entry['items'] ?? [];
                    $created += is_array($items) ? count($items) : 0;
                }

                continue;
            }

            // Create or update the single Menu for this location
            $menu = new Menu(
                id: $menuId,
                tenantId: is_string($firstEntry['tenant_id'] ?? null) ? $firstEntry['tenant_id'] : null,
                location: $location,
                createdAt: $existingMenu !== null ? $existingMenu->createdAt : new DateTimeImmutable(),
                importId: $importId,
            );
            $translations = [];

            // Collect translations from all locale entries
            foreach ($localeEntries as $entry) {
                $locale = is_string($entry['locale'] ?? null) ? $entry['locale'] : 'en';
                $name = is_string($entry['name'] ?? null) ? $entry['name'] : $location;
                $translations[] = new MenuTranslation(
                    menuId: $menuId,
                    locale: $locale,
                    name: $name,
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
                $locale = is_string($entry['locale'] ?? null) ? $entry['locale'] : 'en';
                $items = $entry['items'] ?? [];

                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $sortOrder => $itemData) {
                    if (!is_array($itemData)) {
                        continue;
                    }

                    $pos = is_int($itemData['sort_order'] ?? null) ? $itemData['sort_order'] : (int) $sortOrder;
                    $itemsByPosition[$pos][] = ['item' => $itemData, 'locale' => $locale];
                }
            }

            foreach ($itemsByPosition as $sortOrder => $localeItems) {
                // Use the first locale entry as the canonical item definition
                $firstItem = $localeItems[0]['item'];

                /** @var array<string, mixed> $firstItem */
                $itemImportId = is_string($firstItem['import_id'] ?? null) ? $firstItem['import_id'] : null;
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
                    $contentRef = is_string($firstItem['content_ref']) ? $firstItem['content_ref'] : '';

                    if (isset($contentRefMap[$contentRef])) {
                        $contentId = $contentRefMap[$contentRef];
                    }
                }

                $item = new MenuItem(
                    id: $itemId,
                    menuId: $menuId,
                    parentId: null,
                    contentId: $contentId,
                    url: is_string($firstItem['url'] ?? null) ? $firstItem['url'] : null,
                    target: LinkTarget::tryFrom(is_string($firstItem['target'] ?? null) ? $firstItem['target'] : '_self') ?? LinkTarget::Self,
                    cssClass: is_string($firstItem['css_class'] ?? null) ? $firstItem['css_class'] : null,
                    icon: is_string($firstItem['icon'] ?? null) ? $firstItem['icon'] : null,
                    sortOrder: $sortOrder,
                    visible: is_bool($firstItem['visible'] ?? null) ? $firstItem['visible'] : true,
                    importId: $itemImportId,
                );

                // Build translations from all locales for this item position
                $itemTranslations = [];

                foreach ($localeItems as $localeItem) {
                    $itemLocale = $localeItem['locale'];
                    /** @var array<string, mixed> $itemLocaleData */
                    $itemLocaleData = $localeItem['item'];

                    if (isset($itemLocaleData['label'])) {
                        $itemTranslations[] = new MenuItemTranslation(
                            menuItemId: $itemId,
                            locale: $itemLocale,
                            label: is_string($itemLocaleData['label']) ? $itemLocaleData['label'] : '',
                            titleAttr: is_string($itemLocaleData['title_attr'] ?? null) ? $itemLocaleData['title_attr'] : null,
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
        $siteSettings = $site['settings'] ?? [];

        if (is_array($siteSettings)) {
            foreach ($siteSettings as $key => $value) {
                $created++;

                if (!$dryRun) {
                    $this->settingsService->set('site', (string) $key, $value, null, 'Site definition import');
                }
            }
        }

        // SEO settings
        if ($seo !== []) {
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
            $from = $redirectData['from'] ?? $redirectData['from_path'] ?? null;
            $to = $redirectData['to'] ?? $redirectData['to_path'] ?? null;

            if ($from === null || $to === null) {
                $warnings[] = 'Redirect entry missing from/to path, skipped';

                continue;
            }

            $created++;

            if (!$dryRun) {
                $redirect = new Redirect(
                    id: UuidGenerator::v7(),
                    tenantId: is_string($redirectData['tenant_id'] ?? null) ? $redirectData['tenant_id'] : null,
                    fromPath: is_string($from) ? $from : '',
                    toPath: is_string($to) ? $to : '',
                    statusCode: is_int($redirectData['status_code'] ?? null) ? $redirectData['status_code'] : 301,
                    locale: is_string($redirectData['locale'] ?? null) ? $redirectData['locale'] : null,
                    hits: 0,
                    lastHitAt: null,
                    createdAt: new DateTimeImmutable(),
                    createdBy: is_string($redirectData['created_by'] ?? null) ? $redirectData['created_by'] : 'system',
                    reason: is_string($redirectData['reason'] ?? null) ? $redirectData['reason'] : 'Site definition import',
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
            if (!is_string($locale) || !is_array($transData)) {
                continue;
            }

            $slugSegment = $this->resolveTranslationSlugSegment($transData, $rootSlug);

            $body = is_string($transData['body'] ?? null) ? $transData['body'] : '';
            $body = $this->resolveMediaRefs($body, $mediaRefMap);

            // Resolve og_image
            $ogImageId = null;

            if (isset($transData['og_image'])) {
                $ogImageRef = is_string($transData['og_image']) ? $transData['og_image'] : '';
                $ogImageId = $mediaRefMap[$ogImageRef] ?? null;
            } elseif (isset($itemData['og_image'])) {
                $ogImageRef = is_string($itemData['og_image']) ? $itemData['og_image'] : '';
                $ogImageId = $mediaRefMap[$ogImageRef] ?? null;
            }

            $translationId = UuidGenerator::v7();
            $translation = ContentTranslation::create(
                id: $translationId,
                contentId: $contentId,
                locale: $locale,
                title: is_string($transData['title'] ?? null) ? $transData['title'] : $slugSegment,
                slugSegment: $slugSegment,
                path: ltrim(is_string($transData['path'] ?? null) ? $transData['path'] : $slugSegment, '/'),
                body: $body,
                excerpt: is_string($transData['excerpt'] ?? null) ? $transData['excerpt'] : null,
                metaTitle: is_string($transData['meta_title'] ?? null) ? $transData['meta_title'] : null,
                metaDescription: is_string($transData['meta_description'] ?? null) ? $transData['meta_description'] : null,
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
        $locale = is_string($itemData['locale'] ?? null) ? $itemData['locale'] : 'en';

        $body = is_string($itemData['body'] ?? null) ? $itemData['body'] : '';
        $body = $this->resolveMediaRefs($body, $mediaRefMap);

        $ogImageId = null;

        if (isset($itemData['og_image'])) {
            $ogImageRef = is_string($itemData['og_image']) ? $itemData['og_image'] : '';
            $ogImageId = $mediaRefMap[$ogImageRef] ?? null;
        }

        $translationId = UuidGenerator::v7();
        $translation = ContentTranslation::create(
            id: $translationId,
            contentId: $contentId,
            locale: $locale,
            title: is_string($itemData['title'] ?? null) ? $itemData['title'] : $slug,
            slugSegment: $slug,
            path: ltrim(is_string($itemData['path'] ?? null) ? $itemData['path'] : $slug, '/'),
            body: $body,
            excerpt: is_string($itemData['excerpt'] ?? null) ? $itemData['excerpt'] : null,
            metaTitle: is_string($itemData['meta_title'] ?? null) ? $itemData['meta_title'] : null,
            metaDescription: is_string($itemData['meta_description'] ?? null) ? $itemData['meta_description'] : null,
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
                is_string($mediaData['filename'] ?? null) ? $mediaData['filename'] : basename($source),
                is_string($mediaData['mime_type'] ?? null) ? $mediaData['mime_type'] : $response->headers['content-type'][0] ?? 'application/octet-stream',
            );

            return $this->mediaService->upload(
                $uploadedFile,
                is_string($mediaData['uploader_id'] ?? null) ? $mediaData['uploader_id'] : 'system',
                is_string($mediaData['tenant_id'] ?? null) ? $mediaData['tenant_id'] : null,
                MediaVisibility::Public,
            );
        } finally {
            // tempnam() guarantees the path is within sys_get_temp_dir()
            @unlink($tmpFile);
        }
    }
}
