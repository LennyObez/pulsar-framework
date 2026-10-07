<?php

declare(strict_types=1);

/**
 * Admin moderation action log.
 *
 * @var list<array{id: string, moderator_id: string, action: string, target_type: string, target_id: string, reason: ?string, created_at: string}> $data
 * @var array{moderator_id: ?string} $filter
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $pagination
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lastPage = $pagination['last_page'] ?? 1;
$currentPage = $pagination['current_page'] ?? 1;
$modFilter = $filter['moderator_id'] ?? '';
?>

<div class="forum-admin-page-header">
    <h1>Moderation Log</h1>
    <span style="color:var(--color-text-disabled);font-size:0.875rem"><?= (int) ($pagination['total'] ?? 0) ?> entries</span>
</div>

<form method="get" action="/admin/forum/moderation-log" style="display:flex;gap:var(--space-2);margin-bottom:var(--space-8);max-width:400px">
    <label for="mod-filter" class="sr-only">Filter by moderator</label>
    <input type="text" id="mod-filter" name="moderator_id" class="forum-input" placeholder="Filter by moderator ID..." value="<?= $e($modFilter ?? '') ?>">
    <button type="submit" class="forum-btn forum-btn--secondary">Filter</button>
    <?php if ($modFilter !== null && $modFilter !== ''): ?>
        <a href="/admin/forum/moderation-log" class="forum-btn forum-btn--ghost">Clear</a>
    <?php endif; ?>
</form>

<div class="forum-card">
    <?php if ($data === []): ?>
        <div class="forum-empty">
            <h3>No log entries</h3>
            <p>Moderation actions will appear here.</p>
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse" role="table">
            <thead>
                <tr style="border-bottom:2px solid var(--color-border)">
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Action</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Moderator</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Target</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Reason</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $log): ?>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <td style="padding:var(--space-2) var(--space-6)">
                            <span class="forum-badge forum-badge--pinned"><?= $e($log['action']) ?></span>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6)">
                            <a href="/admin/forum/users/<?= $e($log['moderator_id']) ?>"><?= $e($log['moderator_id']) ?></a>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);font-size:0.875rem">
                            <span style="color:var(--color-text-disabled)"><?= $e($log['target_type']) ?></span>
                            <span style="font-family:var(--font-mono);font-size:0.8125rem"><?= $e($log['target_id']) ?></span>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem"><?= $e($log['reason'] ?? '') ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right">
                            <time datetime="<?= $e($log['created_at']) ?>" style="font-size:0.8125rem;color:var(--color-text-disabled)"><?= $e($log['created_at']) ?></time>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($lastPage > 1): ?>
    <nav class="forum-pagination" aria-label="Pagination">
        <?php $qs = $modFilter ? '&moderator_id=' . $e(urlencode($modFilter)) : ''; ?>
        <?php if ($currentPage > 1): ?><a href="?page=<?= $currentPage - 1 ?><?= $qs ?>">&laquo;</a><?php endif; ?>
        <?php for ($i = 1; $i <= $lastPage; $i++): ?>
            <?php if ($i === $currentPage): ?><span class="active" aria-current="page"><?= $i ?></span><?php else: ?><a href="?page=<?= $i ?><?= $qs ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($currentPage < $lastPage): ?><a href="?page=<?= $currentPage + 1 ?><?= $qs ?>">&raquo;</a><?php endif; ?>
    </nav>
<?php endif; ?>
