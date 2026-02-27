<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FullSiteEditor;

use Pulsar\Api\Api;

/**
 * Persistence for template parts.
 *
 * @psalm-api Public binding contract; implemented by FullSiteEditor service
 *            and consumed by template rendering and admin editor.
 */
#[Api(since: '1.0.0')]
interface TemplatePartRepositoryInterface
{
    public function findById(string $id): ?TemplatePart;

    public function findBySlug(string $slug, string $themeId = ''): ?TemplatePart;

    /**
     * @return list<TemplatePart>
     */
    public function findByArea(TemplatePartArea $area, string $themeId = ''): array;

    /**
     * @return list<TemplatePart>
     */
    public function all(string $themeId = ''): array;

    public function save(TemplatePart $part): void;

    public function delete(string $id): void;
}
