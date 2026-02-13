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
        <a href="/admin/schema" class="admin-btn admin-btn--secondary">&larr; Database</a>
        <a href="/admin/api/schema/changelog/export" class="admin-btn admin-btn--primary" download>Export SQL bundle</a>
    </div>

    <?php if ($entries === []): ?>
    <div class="admin-empty-state">
        <h2 class="admin-empty-state__title">No schema changes recorded</h2>
        <p class="admin-empty-state__description">
            Schema changes will appear here with audit trails and evidence hashes.
        </p>
    </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Status</th>
                <th>Operation</th>
                <th>Table</th>
                <th>Actor</th>
                <th>Reason</th>
                <th>Evidence Hash</th>
                <th>Time</th>
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
