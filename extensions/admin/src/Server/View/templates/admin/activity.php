<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var list<array{id: string, action: string, resource: string, record_id: ?string, actor: string, timestamp: int, success: bool, detail: string}> $entries */
$entries = $templateData['entries'] ?? [];
?>
<div class="admin-activity">
    <?php if ($entries === []): ?>
    <div class="admin-empty-state">
        <div class="admin-empty-state__icon">&#128340;</div>
        <h2 class="admin-empty-state__title" data-t="admin.activity.no_activity"><?= __('admin.activity.no_activity') ?></h2>
        <p class="admin-empty-state__description" data-t="admin.activity.no_activity_hint">
            <?= __('admin.activity.no_activity_hint') ?>
        </p>
    </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th data-t="admin.activity.status"><?= __('admin.activity.status') ?></th>
                <th data-t="admin.activity.action"><?= __('admin.activity.action') ?></th>
                <th data-t="admin.activity.resource"><?= __('admin.activity.resource') ?></th>
                <th data-t="admin.activity.record"><?= __('admin.activity.record') ?></th>
                <th data-t="admin.activity.actor"><?= __('admin.activity.actor') ?></th>
                <th data-t="admin.activity.time"><?= __('admin.activity.time') ?></th>
                <th data-t="admin.activity.detail"><?= __('admin.activity.detail') ?></th>
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
