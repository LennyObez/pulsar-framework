<?php

declare(strict_types=1);

/**
 * Moderation queue: thread and post reports.
 *
 * @var list<array{id: string, thread_id: string, reporter_id: string, reason: string, status: string, created_at: string}> $thread_reports
 * @var list<array{id: string, post_id: string, reporter_id: string, reason: string, status: string, created_at: string}> $post_reports
 * @var array{status: string} $filter
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $thread_pagination
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $post_pagination
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
$activeFilter = $filter['status'] ?? 'pending';
?>

<div class="forum-admin-page-header">
    <h1>Moderation Queue</h1>
</div>

<div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-8)">
    <?php foreach (['pending', 'actioned', 'dismissed'] as $status): ?>
        <a href="?status=<?= $status ?>" class="forum-btn <?= $activeFilter === $status ? 'forum-btn--primary' : 'forum-btn--ghost' ?> forum-btn--sm">
            <?= ucfirst($status) ?>
        </a>
    <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-8)">
    <div class="forum-card">
        <div class="forum-card-header">
            <h2>Thread Reports</h2>
            <span style="font-size:0.8125rem;color:var(--color-text-disabled)"><?= (int) ($thread_pagination['total'] ?? 0) ?> total</span>
        </div>
        <?php if ($thread_reports === []): ?>
            <div class="forum-card-body"><p style="color:var(--color-text-muted)">No thread reports.</p></div>
        <?php else: ?>
            <?php foreach ($thread_reports as $report): ?>
                <div style="padding:var(--space-4) var(--space-6);border-bottom:1px solid var(--color-border)">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-1)">
                        <span style="font-weight:600;font-size:0.875rem">Thread: <?= $e($report['thread_id']) ?></span>
                        <span class="forum-badge forum-badge--<?= $report['status'] === 'pending' ? 'pinned' : ($report['status'] === 'actioned' ? 'solved' : 'closed') ?>">
                            <?= $e($report['status']) ?>
                        </span>
                    </div>
                    <p style="font-size:0.875rem;color:var(--color-text-muted);margin-bottom:var(--space-2)"><?= $e($report['reason']) ?></p>
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span style="font-size:0.75rem;color:var(--color-text-disabled)">
                            Reported by <?= $e($report['reporter_id']) ?>
                            &middot; <time datetime="<?= $e($report['created_at']) ?>"><?= $e($report['created_at']) ?></time>
                        </span>
                        <?php if ($report['status'] === 'pending'): ?>
                            <div style="display:flex;gap:var(--space-1)">
                                <form method="post" action="/admin/forum/moderation/thread-reports/<?= $e($report['id']) ?>" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="action" value="action">
                                    <button type="submit" class="forum-btn forum-btn--primary forum-btn--sm">Action</button>
                                </form>
                                <form method="post" action="/admin/forum/moderation/thread-reports/<?= $e($report['id']) ?>" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="action" value="dismiss">
                                    <button type="submit" class="forum-btn forum-btn--ghost forum-btn--sm">Dismiss</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="forum-card">
        <div class="forum-card-header">
            <h2>Post Reports</h2>
            <span style="font-size:0.8125rem;color:var(--color-text-disabled)"><?= (int) ($post_pagination['total'] ?? 0) ?> total</span>
        </div>
        <?php if ($post_reports === []): ?>
            <div class="forum-card-body"><p style="color:var(--color-text-muted)">No post reports.</p></div>
        <?php else: ?>
            <?php foreach ($post_reports as $report): ?>
                <div style="padding:var(--space-4) var(--space-6);border-bottom:1px solid var(--color-border)">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-1)">
                        <span style="font-weight:600;font-size:0.875rem">Post: <?= $e($report['post_id']) ?></span>
                        <span class="forum-badge forum-badge--<?= $report['status'] === 'pending' ? 'pinned' : ($report['status'] === 'actioned' ? 'solved' : 'closed') ?>">
                            <?= $e($report['status']) ?>
                        </span>
                    </div>
                    <p style="font-size:0.875rem;color:var(--color-text-muted);margin-bottom:var(--space-2)"><?= $e($report['reason']) ?></p>
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span style="font-size:0.75rem;color:var(--color-text-disabled)">
                            Reported by <?= $e($report['reporter_id']) ?>
                            &middot; <time datetime="<?= $e($report['created_at']) ?>"><?= $e($report['created_at']) ?></time>
                        </span>
                        <?php if ($report['status'] === 'pending'): ?>
                            <div style="display:flex;gap:var(--space-1)">
                                <form method="post" action="/admin/forum/moderation/post-reports/<?= $e($report['id']) ?>" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="action" value="action">
                                    <button type="submit" class="forum-btn forum-btn--primary forum-btn--sm">Action</button>
                                </form>
                                <form method="post" action="/admin/forum/moderation/post-reports/<?= $e($report['id']) ?>" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="action" value="dismiss">
                                    <button type="submit" class="forum-btn forum-btn--ghost forum-btn--sm">Dismiss</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
