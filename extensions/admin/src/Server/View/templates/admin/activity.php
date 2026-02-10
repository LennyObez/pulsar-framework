<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
/** @var list<array{id: string, action: string, resource: string, record_id: ?string, actor: string, timestamp: int, success: bool, detail: string}> $entries */
$entries = $templateData['entries'] ?? [];
?>
<div class="admin-activity">
    <?php if ($entries === []): ?>
    <div class="admin-empty-state">
        <div class="admin-empty-state__icon">&#128340;</div>
        <h2 class="admin-empty-state__title">No activity yet</h2>
        <p class="admin-empty-state__description">
            Activity is recorded when you create, edit, or delete records through the admin panel.
            Navigate to a <a href="/admin/resources">resource</a> and perform a CRUD operation to see it logged here.
        </p>
    </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Status</th>
                <th>Action</th>
                <th>Resource</th>
                <th>Record</th>
                <th>Actor</th>
                <th>Time</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $entry): ?>
            <tr>
                <td>
                    <span class="admin-activity__status admin-activity__status--<?= $entry['success'] ? 'ok' : 'fail' ?>"></span>
                </td>
                <td><?= $e($entry['action']) ?></td>
                <td><a href="/admin/resources/<?= $e($entry['resource']) ?>"><?= $e($entry['resource']) ?></a></td>
                <td><?= $entry['record_id'] !== null ? $e($entry['record_id']) : '<span class="admin-field--null">-</span>' ?></td>
                <td><?= $e($entry['actor']) ?></td>
                <td><?= $e(date('Y-m-d H:i:s', $entry['timestamp'])) ?></td>
                <td><?= $e($entry['detail']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
