<?php

declare(strict_types=1);

/**
 * Admin dashboard: overview stats.
 *
 * @var list<array{id: string, title: string, slug: string, author_id: string, status: string, reply_count: int, created_at: string}> $recent_threads
 * @var int $pending_reports_count
 * @var list<array{user_id: string, reputation_score: int, post_count: int, thread_count: int}> $top_contributors
 * @var array{total_threads: int} $stats
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<div class="forum-admin-page-header">
    <h1><?= @t('forum.admin.dashboard.title') ?></h1>
</div>

<div class="forum-stat-grid" style="margin-bottom:var(--space-8)">
    <div class="forum-stat-card">
        <div class="forum-stat-value"><?= (int) ($stats['total_threads'] ?? 0) ?></div>
        <div class="forum-stat-label"><?= @t('forum.admin.dashboard.total_threads') ?></div>
    </div>
    <div class="forum-stat-card">
        <div class="forum-stat-value"><?= (int) $pending_reports_count ?></div>
        <div class="forum-stat-label"><?= @t('forum.admin.dashboard.pending_reports') ?></div>
    </div>
    <div class="forum-stat-card">
        <div class="forum-stat-value"><?= count($top_contributors) ?></div>
        <div class="forum-stat-label"><?= @t('forum.admin.dashboard.top_contributors') ?></div>
    </div>
</div>

<?php if ($pending_reports_count > 0): ?>
    <div class="forum-alert forum-alert--error" role="alert" style="margin-bottom:var(--space-8)">
        <strong><?= @t('forum.admin.dashboard.pending_alert', ['count' => (int) $pending_reports_count]) ?></strong>
        <a href="/admin/forum/moderation" style="margin-left:var(--space-2)"><?= @t('forum.admin.dashboard.review_now') ?></a>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-8)">
    <div class="forum-card">
        <div class="forum-card-header">
            <h2><?= @t('forum.admin.dashboard.recent_threads') ?></h2>
            <a href="/admin/forum/threads" class="forum-btn forum-btn--ghost forum-btn--sm"><?= @t('forum.admin.dashboard.view_all') ?></a>
        </div>
        <?php if ($recent_threads === []): ?>
            <div class="forum-card-body">
                <p style="color:var(--color-text-muted)"><?= @t('forum.admin.dashboard.no_threads') ?></p>
            </div>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)"><?= @t('forum.admin.dashboard.table.thread') ?></th>
                        <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)"><?= @t('forum.admin.dashboard.table.status') ?></th>
                        <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)"><?= @t('forum.admin.dashboard.table.replies') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_threads as $thread): ?>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <td style="padding:var(--space-2) var(--space-6)">
                                <a href="/admin/forum/threads/<?= $e($thread['id']) ?>" style="font-weight:600"><?= $e($thread['title']) ?></a>
                                <div style="font-size:0.75rem;color:var(--color-text-disabled)">
                                    <time datetime="<?= $e($thread['created_at']) ?>"><?= $e($thread['created_at']) ?></time>
                                </div>
                            </td>
                            <td style="padding:var(--space-2) var(--space-6)">
                                <span class="forum-badge forum-badge--<?= $thread['status'] === 'open' ? 'solved' : 'closed' ?>"><?= $e($thread['status']) ?></span>
                            </td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right;color:var(--color-text-muted)">
                                <?= (int) $thread['reply_count'] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="forum-card">
        <div class="forum-card-header">
            <h2><?= @t('forum.admin.dashboard.top_contributors') ?></h2>
            <a href="/admin/forum/leaderboard" class="forum-btn forum-btn--ghost forum-btn--sm"><?= @t('forum.admin.nav.leaderboard') ?></a>
        </div>
        <?php if ($top_contributors === []): ?>
            <div class="forum-card-body">
                <p style="color:var(--color-text-muted)"><?= @t('forum.admin.dashboard.no_contributors') ?></p>
            </div>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)"><?= @t('forum.admin.dashboard.table.user') ?></th>
                        <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)"><?= @t('forum.admin.dashboard.table.rep') ?></th>
                        <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)"><?= @t('forum.admin.dashboard.table.posts') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_contributors as $contrib): ?>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <td style="padding:var(--space-2) var(--space-6)">
                                <a href="/admin/forum/users/<?= $e($contrib['user_id']) ?>"><?= $e($contrib['user_id']) ?></a>
                            </td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:700;color:var(--ext-accent)">
                                <?= (int) $contrib['reputation_score'] ?>
                            </td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right;color:var(--color-text-muted)">
                                <?= (int) $contrib['post_count'] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
