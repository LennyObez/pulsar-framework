<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var list<array{id: string, label: string, size: string, data: array<string, mixed>}> $widgets */
$widgets = $templateData['widgets'] ?? [];
/** @var list<array{name: string, label: string, icon: string}> $resources */
$resources = $templateData['resources'] ?? [];
?>
<div class="admin-dashboard">
    <section class="admin-widgets">
        <?php foreach ($widgets as $widget): ?>
        <div class="admin-widget admin-widget--<?= $e($widget['size']) ?>" data-widget-id="<?= $e($widget['id']) ?>">
            <h3 class="admin-widget__title"><?= $e($widget['label']) ?></h3>
            <div class="admin-widget__content" data-widget-data="<?= $e(json_encode($widget['data'], JSON_THROW_ON_ERROR)) ?>">
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <section class="admin-resources">
        <h2>Resources</h2>
        <div class="admin-resource-grid">
            <?php foreach ($resources as $resource): ?>
            <a href="/admin/resources/<?= $e($resource['name']) ?>" class="admin-resource-card">
                <span class="admin-resource-card__icon"><?= $e($resource['icon']) ?></span>
                <span class="admin-resource-card__label"><?= $e($resource['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>
