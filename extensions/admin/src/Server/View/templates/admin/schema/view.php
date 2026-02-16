<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var string $table */
$table = $templateData['table'] ?? '';
/** @var list<array{name: string, type: string, nullable: bool, primaryKey: bool, default: ?string}> $columns */
$columns = $templateData['columns'] ?? [];
/** @var array<string, bool> $capabilities */
$capabilities = $templateData['capabilities'] ?? [];
/** @var string $driver */
$driver = $templateData['driver'] ?? 'sqlite';
?>
<div
    data-schema-view
    data-table="<?= $e($table) ?>"
    data-columns="<?= $e(json_encode($columns, JSON_THROW_ON_ERROR)) ?>"
    data-capabilities="<?= $e(json_encode($capabilities, JSON_THROW_ON_ERROR)) ?>"
    data-driver="<?= $e($driver) ?>"
>
    <div class="admin-schema-view__header">
        <a href="/admin/schema" class="admin-btn admin-btn--secondary" data-t="admin.schema.all_tables">&larr; <?= __('admin.schema.all_tables') ?></a>
    </div>

    <h2 data-t="admin.schema.columns"><?= __('admin.schema.columns') ?></h2>
    <table class="admin-table">
        <thead>
            <tr>
                <th data-t="admin.schema.col_name"><?= __('admin.schema.col_name') ?></th>
                <th data-t="admin.schema.col_type"><?= __('admin.schema.col_type') ?></th>
                <th data-t="admin.schema.col_nullable"><?= __('admin.schema.col_nullable') ?></th>
                <th data-t="admin.schema.col_default"><?= __('admin.schema.col_default') ?></th>
                <th data-t="admin.schema.col_pk"><?= __('admin.schema.col_pk') ?></th>
                <th data-t="admin.schema.actions"><?= __('admin.schema.actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($columns as $col): ?>
            <tr>
                <td><?= $e($col['name']) ?></td>
                <td><code><?= $e($col['type']) ?></code></td>
                <td><?= $col['nullable'] ? 'Yes' : 'No' ?></td>
                <td><?= $col['default'] !== null ? $e($col['default']) : '<span class="admin-field--null">none</span>' ?></td>
                <td><?= $col['primaryKey'] ? 'Yes' : '' ?></td>
                <td>
                    <?php if ($capabilities['supportsDropColumn'] ?? false): ?>
                    <button
                        class="admin-btn admin-btn--sm admin-btn--danger"
                        data-drop-column="<?= $e($col['name']) ?>"
                        data-table="<?= $e($table) ?>"
                    ><?= __('admin.schema.drop') ?></button>
                    <?php else: ?>
                    <button class="admin-btn admin-btn--sm" disabled title="<?= __('admin.schema.drop_unsupported') ?>"><?= __('admin.schema.drop') ?></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <noscript>
        <div class="admin-alert admin-alert--warning">
            <?= __('admin.schema.js_required') ?>
        </div>
    </noscript>
</div>
