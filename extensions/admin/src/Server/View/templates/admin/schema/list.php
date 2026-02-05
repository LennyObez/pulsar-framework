<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
/** @var list<array{name: string, columns: int, primaryKey: ?string}> $tables */
$tables = $templateData['tables'] ?? [];
/** @var array<string, bool> $capabilities */
$capabilities = $templateData['capabilities'] ?? [];
/** @var string $driver */
$driver = $templateData['driver'] ?? 'sqlite';
?>
<div class="admin-schema">
    <div class="admin-schema__header">
        <div class="admin-schema__actions">
            <a href="/admin/schema/create" class="admin-btn admin-btn--primary">Create Table</a>
            <a href="/admin/schema/changelog" class="admin-btn admin-btn--secondary">Change Log</a>
        </div>
        <?php if (!($capabilities['supportsDropColumn'] ?? true)): ?>
        <div class="admin-alert admin-alert--warning">
            Limited ALTER support: this database driver does not support DROP COLUMN
        </div>
        <?php endif; ?>
        <?php if (!($capabilities['supportsTransactionalDdl'] ?? false)): ?>
        <div class="admin-alert admin-alert--info">
            Schema changes are not atomic on this driver. Changes are applied sequentially.
        </div>
        <?php endif; ?>
    </div>

    <?php if ($tables === []): ?>
    <div class="admin-empty-state">
        <h2 class="admin-empty-state__title">No tables found</h2>
        <p class="admin-empty-state__description">
            Create your first database table using the button above.
        </p>
    </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Table Name</th>
                <th>Columns</th>
                <th>Primary Key</th>
                <th>Actions</th>
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
                    <a href="/admin/schema/<?= $e($table['name']) ?>" class="admin-btn admin-btn--sm">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
