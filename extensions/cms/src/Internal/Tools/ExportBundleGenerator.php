<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_map;
use function bin2hex;
use function in_array;
use function is_array;
use function json_encode;
use function sodium_crypto_generichash;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;

/**
 * Generates export bundles from CMS data with PII redaction and integrity hashing.
 */
#[Internal(reason: 'Import/export internals — use ImportExportServiceInterface')]
final readonly class ExportBundleGenerator
{
    /** PII field names that are redacted when includePii is false. */
    private const array PII_FIELDS = [
        'customer_email',
        'guest_email',
        'ip_hash',
        'user_agent_hash',
        'billing_address',
        'shipping_address',
        'address_line_1',
        'address_line_2',
        'city',
        'postal_code',
        'phone',
    ];

    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private TaxonomyRepositoryInterface $taxonomyRepository,
        private MenuRepositoryInterface $menuRepository,
        private SettingsServiceInterface $settingsService,
        private MediaRepositoryInterface $mediaRepository,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    public function exportBundle(ExportOptions $options): ExportBundle
    {
        $data = [];

        if (in_array('content', $options->scope, true)) {
            $data['content'] = $this->exportContent($options);
        }

        if (in_array('taxonomies', $options->scope, true)) {
            $data['taxonomies'] = $this->exportTaxonomies($options);
        }

        if (in_array('menus', $options->scope, true)) {
            $data['menus'] = $this->exportMenus($options);
        }

        if (in_array('settings', $options->scope, true)) {
            $data['settings'] = $this->exportSettings($options);
        }

        if (in_array('media_refs', $options->scope, true)) {
            $data['media_refs'] = $this->exportMediaRefs($options);
        }

        if (!$options->includePii) {
            $data = $this->redactPii($data);
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $evidenceHash = bin2hex(sodium_crypto_generichash($json, '', SODIUM_CRYPTO_GENERICHASH_BYTES));

        $bundle = new ExportBundle(
            data: $data,
            evidenceHash: $evidenceHash,
            createdAt: new DateTimeImmutable(),
            scope: $options->scope,
            piiIncluded: $options->includePii,
        );

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            null,
            'cms.export.completed',
            'cms:export',
            [
                'scope' => $options->scope,
                'evidence_hash' => $evidenceHash,
                'pii_included' => $options->includePii,
            ],
        );

        return $bundle;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportContent(ExportOptions $options): array
    {
        $result = $this->contentRepository->findPublished(
            locale: $options->locales[0] ?? 'en',
            tenantId: $options->tenantId,
            perPage: 10000,
        );

        return array_map(
            static fn(object $content): array => (array) $content,
            $result->items,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportTaxonomies(ExportOptions $options): array
    {
        $taxonomies = [];
        $locale = $options->locales[0] ?? 'en';

        foreach (['category', 'tag'] as $slug) {
            $taxonomy = $this->taxonomyRepository->findBySlug($slug, $options->tenantId);

            if ($taxonomy === null) {
                continue;
            }

            $terms = $this->taxonomyRepository->findTerms($taxonomy->id, $locale);

            $taxonomies[] = [
                'id' => $taxonomy->id,
                'slug' => $taxonomy->slug,
                'hierarchical' => $taxonomy->hierarchical,
                'terms' => array_map(
                    static fn(object $term): array => (array) $term,
                    $terms,
                ),
            ];
        }

        return $taxonomies;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportMenus(ExportOptions $options): array
    {
        $menus = [];
        $locale = $options->locales[0] ?? 'en';

        foreach (['primary', 'footer', 'sidebar'] as $location) {
            $menu = $this->menuRepository->findByLocation($location, $locale, $options->tenantId);

            if ($menu === null) {
                continue;
            }

            $menus[] = [
                'id' => $menu->id,
                'location' => $menu->location,
            ];
        }

        return $menus;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function exportSettings(ExportOptions $options): array
    {
        $locale = $options->locales[0] ?? null;

        return $this->settingsService->getAll($locale);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportMediaRefs(ExportOptions $options): array
    {
        $result = $this->mediaRepository->listAssets(
            tenantId: $options->tenantId,
            page: 1,
            perPage: 10000,
        );

        return array_map(
            static fn(object $asset): array => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'mime_type' => $asset->mimeType,
                'file_size' => $asset->fileSize,
                'storage_path' => $asset->storagePath,
            ],
            $result->items,
        );
    }

    /**
     * Recursively redact PII fields from exported data.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function redactPii(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redactPii($value);
            } elseif (in_array($key, self::PII_FIELDS, true) && $value !== null) {
                $redacted[$key] = '[redacted]';
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}
