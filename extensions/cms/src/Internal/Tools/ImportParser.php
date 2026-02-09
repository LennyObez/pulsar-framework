<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
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
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function is_array;
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
 */
#[Internal(reason: 'Import/export internals — use ImportExportServiceInterface')]
final readonly class ImportParser
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
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

        $created = [];
        $updated = [];
        $skipped = [];
        $warnings = [];
        $errors = [];

        if (isset($data['taxonomies']) && is_array($data['taxonomies'])) {
            $result = $this->processTaxonomies($data['taxonomies'], $dryRun);
            $created['taxonomies'] = $result['created'];
            $updated['taxonomies'] = $result['updated'];
            $skipped['taxonomies'] = $result['skipped'];
            $warnings = [...$warnings, ...$result['warnings']];
        }

        if (isset($data['content']) && is_array($data['content'])) {
            $result = $this->processContent($data['content'], $dryRun);
            $created['content'] = $result['created'];
            $updated['content'] = $result['updated'];
            $skipped['content'] = $result['skipped'];
            $warnings = [...$warnings, ...$result['warnings']];
        }

        if (isset($data['menus']) && is_array($data['menus'])) {
            $result = $this->processMenus($data['menus'], $dryRun);
            $created['menus'] = $result['created'];
            $updated['menus'] = $result['updated'];
            $skipped['menus'] = $result['skipped'];
            $warnings = [...$warnings, ...$result['warnings']];
        }

        if (isset($data['settings']) && is_array($data['settings'])) {
            $result = $this->processSettings($data['settings'], $dryRun);
            $created['settings'] = $result['created'];
            $updated['settings'] = $result['updated'];
            $warnings = [...$warnings, ...$result['warnings']];
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
            $slug = $taxData['slug'] ?? null;

            if ($slug === null || $slug === '') {
                $warnings[] = 'Taxonomy entry missing slug, skipped';
                $skipped++;

                continue;
            }

            $existing = $this->taxonomyRepository->findBySlug($slug, $taxData['tenant_id'] ?? null);

            if ($existing !== null) {
                $updated++;

                if (!$dryRun && isset($taxData['terms']) && is_array($taxData['terms'])) {
                    $this->importTaxonomyTerms($existing->id, $taxData['terms'], $taxData['tenant_id'] ?? null);
                }
            } else {
                $created++;

                if (!$dryRun) {
                    $taxonomyId = UuidGenerator::v7();
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

                    if (isset($taxData['terms']) && is_array($taxData['terms'])) {
                        $this->importTaxonomyTerms($taxonomyId, $taxData['terms'], $taxData['tenant_id'] ?? null);
                    }
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * @param list<array<string, mixed>> $terms
     */
    private function importTaxonomyTerms(string $taxonomyId, array $terms, ?string $tenantId): void
    {
        foreach ($terms as $termData) {
            $termId = UuidGenerator::v7();
            $term = new TaxonomyTerm(
                id: $termId,
                taxonomyId: $taxonomyId,
                tenantId: $tenantId,
                parentId: null,
                sortOrder: $termData['sort_order'] ?? 0,
                createdAt: new DateTimeImmutable(),
            );
            $translations = [];

            if (isset($termData['name'])) {
                $translations[] = new TaxonomyTermTranslation(
                    termId: $termId,
                    locale: $termData['locale'] ?? 'en',
                    name: $termData['name'],
                    slug: $termData['slug'] ?? $this->slugify($termData['name']),
                    description: $termData['description'] ?? null,
                );
            }

            $this->taxonomyRepository->saveTerm($term, $translations);
        }
    }

    /**
     * @param list<array<string, mixed>> $contentItems
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>}
     */
    private function processContent(array $contentItems, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($contentItems as $itemData) {
            $slug = $itemData['slug'] ?? $itemData['slugSegment'] ?? null;
            $locale = $itemData['locale'] ?? 'en';

            if ($slug === null || $slug === '') {
                $warnings[] = 'Content entry missing slug, skipped';
                $skipped++;

                continue;
            }

            $existing = $this->contentRepository->findByPath($locale, $slug, $itemData['tenant_id'] ?? null);

            if ($existing !== null) {
                $updated++;
            } else {
                $created++;
            }

            if (!$dryRun && $existing === null) {
                $contentId = UuidGenerator::v7();
                $contentType = ContentType::tryFrom($itemData['content_type'] ?? 'page') ?? ContentType::Page;

                $content = Content::create(
                    id: $contentId,
                    contentType: $contentType,
                    authorId: $itemData['author_id'] ?? 'system',
                    tenantId: $itemData['tenant_id'] ?? null,
                );

                $this->contentRepository->save($content);
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * @param list<array<string, mixed>> $menus
     *
     * @return array{created: int, updated: int, skipped: int, warnings: list<string>}
     */
    private function processMenus(array $menus, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($menus as $menuData) {
            $location = $menuData['location'] ?? null;
            $locale = $menuData['locale'] ?? 'en';

            if ($location === null || $location === '') {
                $warnings[] = 'Menu entry missing location, skipped';
                $skipped++;

                continue;
            }

            $existing = $this->menuRepository->findByLocation($location, $locale, $menuData['tenant_id'] ?? null);

            if ($existing !== null) {
                $updated++;
            } else {
                $created++;

                if (!$dryRun) {
                    $menuId = UuidGenerator::v7();
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

                    if (isset($menuData['items']) && is_array($menuData['items'])) {
                        $this->importMenuItems($menuId, $menuData['items'], $locale);
                    }
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function importMenuItems(string $menuId, array $items, string $locale): void
    {
        foreach ($items as $sortOrder => $itemData) {
            $itemId = UuidGenerator::v7();
            $item = new MenuItem(
                id: $itemId,
                menuId: $menuId,
                parentId: null,
                contentId: $itemData['content_id'] ?? null,
                url: $itemData['url'] ?? null,
                target: LinkTarget::tryFrom($itemData['target'] ?? '_self') ?? LinkTarget::Self,
                cssClass: $itemData['css_class'] ?? null,
                icon: $itemData['icon'] ?? null,
                sortOrder: $itemData['sort_order'] ?? $sortOrder,
                visible: $itemData['visible'] ?? true,
            );
            $translations = [];

            if (isset($itemData['label'])) {
                $translations[] = new MenuItemTranslation(
                    menuItemId: $itemId,
                    locale: $locale,
                    label: $itemData['label'],
                    titleAttr: $itemData['title_attr'] ?? null,
                );
            }

            $this->menuRepository->saveItem($item, $translations);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $settings
     *
     * @return array{created: int, updated: int, warnings: list<string>}
     */
    private function processSettings(array $settings, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $warnings = [];

        foreach ($settings as $group => $keys) {
            if (!is_array($keys)) {
                $warnings[] = "Settings group '{$group}' has invalid format, skipped";

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
