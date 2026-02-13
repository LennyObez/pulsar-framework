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
        <a href="/admin/schema" class="admin-btn admin-btn--secondary">&larr; All tables</a>
    </div>

    <h2>Columns</h2>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Nullable</th>
                <th>Default</th>
                <th>PK</th>
                <th>Actions</th>
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
                    >Drop</button>
                    <?php else: ?>
                    <button class="admin-btn admin-btn--sm" disabled title="DROP COLUMN not supported by this driver">Drop</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <noscript>
        <div class="admin-alert admin-alert--warning">
            JavaScript is required for schema modification actions.
        </div>
    </noscript>
</div>
