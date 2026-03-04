<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FullSiteEditor;

use DateTimeImmutable;
use InvalidArgumentException;
use Pulsar\Api\Api;

use function bin2hex;
use function preg_match;
use function random_bytes;
use function strtolower;
use function trim;

/**
 * Orchestrates full-site editing: template parts, global styles, and CSS variables.
 *
 * This service coordinates between the template part repository and global
 * styles repository, providing validation and slug normalization.
 */
#[Api(since: '1.0.0')]
final readonly class FullSiteEditorService
{
    public function __construct(
        private TemplatePartRepositoryInterface $partRepository,
        private GlobalStylesRepositoryInterface $stylesRepository,
    ) {}

    /**
     * Create a new template part.
     *
     * @throws InvalidArgumentException If slug is invalid
     */
    public function createTemplatePart(
        string $slug,
        TemplatePartArea $area,
        string $name,
        string $content = '[]',
        string $themeId = '',
    ): TemplatePart {
        $slug = $this->normalizeSlug($slug);

        $existing = $this->partRepository->findBySlug($slug, $themeId);

        if ($existing !== null) {
            throw new InvalidArgumentException("Template part with slug '{$slug}' already exists");
        }

        $part = new TemplatePart(
            id: bin2hex(random_bytes(16)),
            slug: $slug,
            area: $area,
            name: $name,
            content: $content,
            themeId: $themeId,
            updatedAt: new DateTimeImmutable(),
        );

        $this->partRepository->save($part);

        return $part;
    }

    /**
     * Update a template part's content.
     *
     * @throws InvalidArgumentException If template part not found
     */
    public function updateTemplatePart(string $id, string $content, ?string $name = null): TemplatePart
    {
        $existing = $this->partRepository->findById($id);

        if ($existing === null) {
            throw new InvalidArgumentException("Template part '{$id}' not found");
        }

        $updated = new TemplatePart(
            id: $existing->id,
            slug: $existing->slug,
            area: $existing->area,
            name: $name ?? $existing->name,
            content: $content,
            themeId: $existing->themeId,
            updatedAt: new DateTimeImmutable(),
        );

        $this->partRepository->save($updated);

        return $updated;
    }

    /**
     * Get all template parts for a specific area.
     *
     * @return list<TemplatePart>
     */
    public function getPartsForArea(TemplatePartArea $area, string $themeId = ''): array
    {
        return $this->partRepository->findByArea($area, $themeId);
    }

    /**
     * Get all template parts.
     *
     * @return list<TemplatePart>
     */
    public function getAllParts(string $themeId = ''): array
    {
        return $this->partRepository->all($themeId);
    }

    /**
     * Delete a template part.
     */
    public function deleteTemplatePart(string $id): void
    {
        $this->partRepository->delete($id);
    }

    /**
     * Load the current global styles configuration.
     */
    public function getGlobalStyles(string $themeId = ''): GlobalStylesConfig
    {
        return $this->stylesRepository->load($themeId);
    }

    /**
     * Save updated global styles configuration.
     */
    public function saveGlobalStyles(GlobalStylesConfig $config, string $themeId = ''): void
    {
        $this->stylesRepository->save($config, $themeId);
    }

    /**
     * Generate the compiled CSS output for the current global styles.
     */
    public function compileGlobalCss(string $themeId = ''): string
    {
        return $this->stylesRepository->load($themeId)->toCss();
    }

    /**
     * Normalize a slug to lowercase alphanumeric with hyphens.
     */
    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        if ($slug === '') {
            throw new InvalidArgumentException('Template part slug cannot be empty');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $slug)) {
            throw new InvalidArgumentException("Invalid slug: '{$slug}'. Use lowercase letters, numbers, and hyphens.");
        }

        return $slug;
    }
}
