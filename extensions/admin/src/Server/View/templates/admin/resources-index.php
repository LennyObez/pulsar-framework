<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var list<array{name: string, label: string, plural_label: string, icon: string, operations: list<string>}> $resources */
$resources = $templateData['resources'] ?? [];
?>
<div class="admin-resources-index">
    <?php if ($resources === []): ?>
    <div class="admin-empty-state">
        <div class="admin-empty-state__icon">&#128451;</div>
        <h2 class="admin-empty-state__title" data-t="admin.resources.no_resources"><?= __('admin.resources.no_resources') ?></h2>
        <p class="admin-empty-state__description" data-t="admin.resources.no_resources_hint">
            <?= __('admin.resources.no_resources_hint') ?>
        </p>
    </div>
    <?php else: ?>
    <div class="admin-resource-grid">
        <?php foreach ($resources as $resource): ?>
        <a href="/admin/resources/<?= $e($resource['name']) ?>" class="admin-resource-card">
            <span class="admin-resource-card__icon"><?= $e($resource['icon']) ?></span>
            <span class="admin-resource-card__label"><?= $e($resource['plural_label']) ?></span>
            <span class="admin-resource-card__ops">
                <?= $e(implode(', ', $resource['operations'])) ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
