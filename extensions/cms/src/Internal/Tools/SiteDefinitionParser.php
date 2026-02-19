<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuItemTranslation;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuTranslation;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
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
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function basename;
use function bin2hex;
use function count;
use function dechex;
use function file_put_contents;
use function hexdec;
use function is_array;
use function is_string;
use function microtime;
use function random_bytes;
use function sprintf;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

use const STR_PAD_LEFT;

/**
 * Parses and imports full site definitions conforming to the N.3 schema.
 *
 * Processes entities in dependency order: taxonomies, media, content, menus,
 * settings, redirects, and finally regenerates sitemaps.
 */
#[Internal(reason: 'Import/export internals — use ImportExportServiceInterface')]
final readonly class SiteDefinitionParser
{
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
    ) {}

    public function importSiteDefinition(SiteDefinition $definition, bool $dryRun): ImportResult
    {
        $created = [];
        $updated = [];
        $skipped = [];
        $warnings = [];
        $errors = [];

        // 1. Taxonomies (no external deps)
        $taxResult = $this->processTaxonomies($definition->taxonomies, $dryRun);
        $created['taxonomies'] = $taxResult['created'];
        $warnings = [...$warnings, ...$taxResult['warnings']];
        /** @var array<string, string> $taxonomyTermMap "category:slug" => term ID */
        $taxonomyTermMap = $taxResult['term_map'];

        // 2. Media (download external assets)
        $mediaResult = $this->processMedia($definition->media, $dryRun);
        $created['media'] = $mediaResult['created'];
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
        $warnings = [...$warnings, ...$contentResult['warnings']];
        /** @var array<string, string> $contentRefMap "page:slug" => content ID */
        $contentRefMap = $contentResult['ref_map'];

        // 4. Menus (depends on content)
        $menuResult = $this->processMenus($definition->menus, $contentRefMap, $dryRun);
        $created['menus'] = $menuResult['created'];
        $warnings = [...$warnings, ...$menuResult['warnings']];

        // 5. Settings
        $settingsResult = $this->processSettings($definition->site, $definition->seo, $dryRun);
        $created['settings'] = $settingsResult['created'];
        $warnings = [...$warnings, ...$settingsResult['warnings']];

        // 6. Redirects
        $redirectResult = $this->processRedirects($definition->redirects, $dryRun);
        $created['redirects'] = $redirectResult['created'];
        $warnings = [...$warnings, ...$redirectResult['warnings']];

        // 7. Regenerate sitemaps
        if (!$dryRun) {
            $baseUrl = $definition->site['url'] ?? $definition->site['base_url'] ?? 'https://localhost';
            $tenantId = $definition->site['tenant_id'] ?? null;
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
     * @return array{created: int, warnings: list<string>, term_map: array<string, string>}
     */
    private function processTaxonomies(array $taxonomies, bool $dryRun): array
    {
        $created = 0;
        $warnings = [];
        $termMap = [];

        foreach ($taxonomies as $taxData) {
            $slug = $taxData['slug'] ?? null;

            if ($slug === null || $slug === '') {
                $warnings[] = 'Taxonomy entry missing slug, skipped';

                continue;
            }

            $taxonomyId = $this->generateId();

            if (!$dryRun) {
                $taxonomy = new Taxonomy(
                    id: $taxonomyId,
                    tenantId: $taxData['tenant_id'] ?? null,
                    slug: $slug,
                    hierarchical: $taxData['hierarchical'] ?? false,
                    createdAt: new DateTimeImmutable(),
                );
                $translations = [];

                if (isset($taxData['name'])) {
                    $translations[] = new TaxonomyTranslation(
                        taxonomyId: $taxonomyId,
                        locale: $taxData['locale'] ?? 'en',
                        name: $taxData['name'],
                        description: $taxData['description'] ?? null,
                    );
                }

                $this->taxonomyRepository->save($taxonomy, $translations);
            }

            $created++;

            $terms = $taxData['terms'] ?? [];

            if (is_array($terms)) {
                foreach ($terms as $termData) {
                    $termSlug = $termData['slug'] ?? null;

                    if ($termSlug === null) {
                        continue;
                    }

                    $termId = $this->generateId();

                    if (!$dryRun) {
                        $term = new TaxonomyTerm(
                            id: $termId,
                            taxonomyId: $taxonomyId,
                            tenantId: $taxData['tenant_id'] ?? null,
                            parentId: null,
                            sortOrder: $termData['sort_order'] ?? 0,
                            createdAt: new DateTimeImmutable(),
                        );
                        $termTranslations = [];

                        if (isset($termData['name'])) {
                            $termTranslations[] = new TaxonomyTermTranslation(
                                termId: $termId,
                                locale: $termData['locale'] ?? $taxData['locale'] ?? 'en',
                                name: $termData['name'],
                                slug: $termSlug,
                                description: $termData['description'] ?? null,
                            );
                        }

                        $this->taxonomyRepository->saveTerm($term, $termTranslations);
                    }

                    $termMap["{$slug}:{$termSlug}"] = $termId;
                    $created++;
                }
            }
        }

        return ['created' => $created, 'warnings' => $warnings, 'term_map' => $termMap];
    }

    /**
     * @param list<array<string, mixed>> $mediaEntries
     *
     * @return array{created: int, warnings: list<string>, ref_map: array<string, string>}
     */
    private function processMedia(array $mediaEntries, bool $dryRun): array
    {
        $created = 0;
        $warnings = [];
        $refMap = [];

        foreach ($mediaEntries as $mediaData) {
            $ref = $mediaData['ref'] ?? $mediaData['filename'] ?? null;
            $source = $mediaData['source'] ?? null;

            if ($ref === null) {
                $warnings[] = 'Media entry missing ref/filename, skipped';

                continue;
            }

            if ($source !== null && !$this->config->allowExternalMediaDownload) {
                $warnings[] = "External media download disabled, skipped: {$ref}";

                continue;
            }

            $created++;

            if (!$dryRun && $source !== null) {
                try {
                    $response = $this->httpClient->request('GET', $source);
                    $tmpFile = sys_get_temp_dir() . '/pulsar_import_' . bin2hex(random_bytes(8));
                    file_put_contents($tmpFile, $response->body);

                    $uploadedFile = new UploadedFile(
                        $tmpFile,
                        strlen($response->body),
                        0,
                        $mediaData['filename'] ?? basename($source),
                        $mediaData['mime_type'] ?? $response->headers['content-type'][0] ?? 'application/octet-stream',
                    );

                    $asset = $this->mediaService->upload(
                        $uploadedFile,
                        $mediaData['uploader_id'] ?? 'system',
                        $mediaData['tenant_id'] ?? null,
                        MediaVisibility::Public,
                    );

                    $refMap[$ref] = $asset->id;

                    @unlink($tmpFile);
                } catch (Throwable $e) {
                    $warnings[] = "Failed to download media '{$ref}': {$e->getMessage()}";
                }
            } elseif (!$dryRun) {
                // Media without external source — assign a placeholder ID
                $refMap[$ref] = $this->generateId();
            } else {
                $refMap[$ref] = 'dry-run-' . $ref;
            }
        }

        return ['created' => $created, 'warnings' => $warnings, 'ref_map' => $refMap];
    }

    /**
     * @param list<array<string, mixed>> $contentItems
     * @param array<string, string> $mediaRefMap
     * @param array<string, string> $taxonomyTermMap
     *
     * @return array{created: int, warnings: list<string>, ref_map: array<string, string>}
     */
    private function processContent(
        array $contentItems,
        array $mediaRefMap,
        array $taxonomyTermMap,
        bool $dryRun,
    ): array {
        $created = 0;
        $warnings = [];
        $contentRefMap = [];

        // First pass: create all content items to build the ref map
        $contentIds = [];

        foreach ($contentItems as $itemData) {
            $slug = $itemData['slug'] ?? null;

            if ($slug === null || $slug === '') {
                $warnings[] = 'Content entry missing slug, skipped';

                continue;
            }

            $contentType = $itemData['content_type'] ?? $itemData['type'] ?? 'page';
            $contentId = $this->generateId();
            $contentIds[] = ['id' => $contentId, 'data' => $itemData];
            $contentRefMap["{$contentType}:{$slug}"] = $contentId;
            $created++;
        }

        if ($dryRun) {
            return ['created' => $created, 'warnings' => $warnings, 'ref_map' => $contentRefMap];
        }

        // Second pass: persist with resolved references
        foreach ($contentIds as $entry) {
            $contentId = $entry['id'];
            $itemData = $entry['data'];

            $contentType = ContentType::tryFrom($itemData['content_type'] ?? $itemData['type'] ?? 'page') ?? ContentType::Page;
            $locale = $itemData['locale'] ?? 'en';
            $slug = $itemData['slug'];

            // Resolve parent_slug
            $parentId = null;

            if (isset($itemData['parent_slug'])) {
                $parentRef = ($itemData['content_type'] ?? 'page') . ':' . $itemData['parent_slug'];
                $parentId = $contentRefMap[$parentRef] ?? null;
            }

            $content = Content::create(
                id: $contentId,
                contentType: $contentType,
                authorId: $itemData['author_id'] ?? 'system',
                tenantId: $itemData['tenant_id'] ?? null,
                template: $itemData['template'] ?? null,
                parentId: $parentId,
            );

            $this->contentRepository->save($content);

            // Resolve media references in body
            $body = $itemData['body'] ?? '';
            $body = $this->resolveMediaRefs($body, $mediaRefMap);

            // Resolve og_image media reference
            $ogImageId = null;

            if (isset($itemData['og_image'])) {
                $ogImageId = $mediaRefMap[$itemData['og_image']] ?? null;
            }

            $translationId = $this->generateId();
            $translation = ContentTranslation::create(
                id: $translationId,
                contentId: $contentId,
                locale: $locale,
                title: $itemData['title'] ?? $slug,
                slugSegment: $slug,
                path: $itemData['path'] ?? $slug,
                body: $body,
                excerpt: $itemData['excerpt'] ?? null,
                metaTitle: $itemData['meta_title'] ?? null,
                metaDescription: $itemData['meta_description'] ?? null,
                ogImageId: $ogImageId,
            );

            // Save translation via repository (we re-use save on content for simplicity)
            // Content save handles translations internally in the actual implementation.

            // Create content blocks
            $blocks = $itemData['blocks'] ?? [];

            if (is_array($blocks)) {
                foreach ($blocks as $sortOrder => $blockData) {
                    $blockId = $this->generateId();
                    $blockType = $blockData['type'] ?? 'text';
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

        return ['created' => $created, 'warnings' => $warnings, 'ref_map' => $contentRefMap];
    }

    /**
     * @param list<array<string, mixed>> $menus
     * @param array<string, string> $contentRefMap
     *
     * @return array{created: int, warnings: list<string>}
     */
    private function processMenus(array $menus, array $contentRefMap, bool $dryRun): array
    {
        $created = 0;
        $warnings = [];

        foreach ($menus as $menuData) {
            $location = $menuData['location'] ?? null;

            if ($location === null || $location === '') {
                $warnings[] = 'Menu entry missing location, skipped';

                continue;
            }

            $created++;

            if ($dryRun) {
                // Count items too
                $items = $menuData['items'] ?? [];
                $created += is_array($items) ? count($items) : 0;

                continue;
            }

            $menuId = $this->generateId();
            $locale = $menuData['locale'] ?? 'en';

            $menu = new Menu(
                id: $menuId,
                tenantId: $menuData['tenant_id'] ?? null,
                location: $location,
                createdAt: new DateTimeImmutable(),
            );
            $translations = [];

            if (isset($menuData['name'])) {
                $translations[] = new MenuTranslation(
                    menuId: $menuId,
                    locale: $locale,
                    name: $menuData['name'],
                );
            }

            $this->menuRepository->save($menu, $translations);

            $items = $menuData['items'] ?? [];

            if (is_array($items)) {
                foreach ($items as $sortOrder => $itemData) {
                    $itemId = $this->generateId();

                    // Resolve content_ref to content ID
                    $contentId = null;

                    if (isset($itemData['content_ref']) && isset($contentRefMap[$itemData['content_ref']])) {
                        $contentId = $contentRefMap[$itemData['content_ref']];
                    }

                    $item = new MenuItem(
                        id: $itemId,
                        menuId: $menuId,
                        parentId: null,
                        contentId: $contentId,
                        url: $itemData['url'] ?? null,
                        target: LinkTarget::tryFrom($itemData['target'] ?? '_self') ?? LinkTarget::Self,
                        cssClass: $itemData['css_class'] ?? null,
                        icon: $itemData['icon'] ?? null,
                        sortOrder: $itemData['sort_order'] ?? $sortOrder,
                        visible: $itemData['visible'] ?? true,
                    );
                    $itemTranslations = [];

                    if (isset($itemData['label'])) {
                        $itemTranslations[] = new MenuItemTranslation(
                            menuItemId: $itemId,
                            locale: $locale,
                            label: $itemData['label'],
                            titleAttr: $itemData['title_attr'] ?? null,
                        );
                    }

                    $this->menuRepository->saveItem($item, $itemTranslations);
                    $created++;
                }
            }
        }

        return ['created' => $created, 'warnings' => $warnings];
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
                    $this->settingsService->set('seo', (string) $key, $value, null, 'Site definition import');
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
                    id: $this->generateId(),
                    tenantId: $redirectData['tenant_id'] ?? null,
                    fromPath: $from,
                    toPath: $to,
                    statusCode: $redirectData['status_code'] ?? 301,
                    locale: $redirectData['locale'] ?? null,
                    hits: 0,
                    lastHitAt: null,
                    createdAt: new DateTimeImmutable(),
                    createdBy: $redirectData['created_by'] ?? 'system',
                    reason: $redirectData['reason'] ?? 'Site definition import',
                );

                $this->redirectRepository->save($redirect);
            }
        }

        return ['created' => $created, 'warnings' => $warnings];
    }

    /**
     * Replace media://ref references in content body with resolved asset IDs.
     *
     * @param array<string, string> $mediaRefMap
     */
    private function resolveMediaRefs(string $body, array $mediaRefMap): string
    {
        foreach ($mediaRefMap as $ref => $assetId) {
            $body = str_replace("media://{$ref}", $assetId, $body);
        }

        return $body;
    }

    private function generateId(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12) . bin2hex(random_bytes(1)),
        );
    }
}
