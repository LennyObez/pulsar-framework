<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var list<array{id: string, operation: string, table: string, actor: string, reason: string, timestamp: int, evidence_hash: string, success: bool}> $entries */
$entries = $templateData['entries'] ?? [];
?>
<div class="admin-schema-changelog">
    <div class="admin-schema-changelog__header">
        <a href="/admin/schema" class="admin-btn admin-btn--secondary" data-t="admin.schema.back_to_database">&larr; <?= __('admin.schema.back_to_database') ?></a>
        <a href="/admin/api/schema/changelog/export" class="admin-btn admin-btn--primary" data-t="admin.schema.export_sql" download><?= __('admin.schema.export_sql') ?></a>
    </div>

    <?php if ($entries === []): ?>
    <div class="admin-empty-state">
        <h2 class="admin-empty-state__title" data-t="admin.schema.no_changes"><?= __('admin.schema.no_changes') ?></h2>
        <p class="admin-empty-state__description" data-t="admin.schema.no_changes_hint">
            <?= __('admin.schema.no_changes_hint') ?>
        </p>
    </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th data-t="admin.activity.status"><?= __('admin.activity.status') ?></th>
                <th data-t="admin.schema.operation"><?= __('admin.schema.operation') ?></th>
                <th data-t="admin.schema.table"><?= __('admin.schema.table') ?></th>
                <th data-t="admin.activity.actor"><?= __('admin.activity.actor') ?></th>
                <th data-t="admin.schema.reason"><?= __('admin.schema.reason') ?></th>
                <th data-t="admin.schema.evidence_hash"><?= __('admin.schema.evidence_hash') ?></th>
                <th data-t="admin.activity.time"><?= __('admin.activity.time') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $entry): ?>
            <tr>
                <td>
                    <span class="admin-activity__status admin-activity__status--<?= $entry['success'] ? 'ok' : 'fail' ?>"></span>
                </td>
                <td><code><?= $e($entry['operation']) ?></code></td>
                <td><?= $e($entry['table']) ?></td>
                <td><?= $e($entry['actor']) ?></td>
                <td><?= $e($entry['reason']) ?></td>
                <td><code title="<?= $e($entry['evidence_hash']) ?>"><?= $e(substr($entry['evidence_hash'], 0, 12)) ?>...</code></td>
                <td><?= $e(date('Y-m-d H:i:s', $entry['timestamp'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
