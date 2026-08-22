<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var list<array{name: string, columns: int, primaryKey: ?string}> $tables */
$tables = $templateData['tables'] ?? [];
/** @var array<string, bool> $capabilities */
$capabilities = $templateData['capabilities'] ?? [];
?>
<div class="admin-schema">
    <div class="admin-schema__header">
        <div class="admin-schema__actions">
            <a href="/admin/schema/create" class="admin-btn admin-btn--primary" data-t="admin.schema.create"><?= __('admin.schema.create') ?></a>
            <a href="/admin/schema/changelog" class="admin-btn admin-btn--secondary" data-t="admin.schema.changelog"><?= __('admin.schema.changelog') ?></a>
        </div>
        <?php if (!($capabilities['supportsDropColumn'] ?? true)): ?>
        <div class="admin-alert admin-alert--warning">
            <?= __('admin.schema.limited_alter') ?>
        </div>
        <?php endif; ?>
        <?php if (!($capabilities['supportsTransactionalDdl'] ?? false)): ?>
        <div class="admin-alert admin-alert--info">
            <?= __('admin.schema.non_atomic') ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($tables === []): ?>
    <div class="admin-empty-state">
        <h2 class="admin-empty-state__title" data-t="admin.schema.no_tables"><?= __('admin.schema.no_tables') ?></h2>
        <p class="admin-empty-state__description" data-t="admin.schema.no_tables_hint">
            <?= __('admin.schema.no_tables_hint') ?>
        </p>
    </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th data-t="admin.schema.table_name"><?= __('admin.schema.table_name') ?></th>
                <th data-t="admin.schema.columns"><?= __('admin.schema.columns') ?></th>
                <th data-t="admin.schema.primary_key"><?= __('admin.schema.primary_key') ?></th>
                <th data-t="admin.schema.actions"><?= __('admin.schema.actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tables as $table): ?>
            <tr>
                <td>
                    <a href="/admin/schema/<?= $e($table['name']) ?>"><?= $e($table['name']) ?></a>
                </td>
                <td><?= $table['columns'] ?></td>
                <td><?= $table['primaryKey'] !== null ? $e($table['primaryKey']) : '<span class="admin-field--null">none</span>' ?></td>
                <td>
                    <a href="/admin/schema/<?= $e($table['name']) ?>" class="admin-btn admin-btn--sm" data-t="admin.schema.view"><?= __('admin.schema.view') ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
