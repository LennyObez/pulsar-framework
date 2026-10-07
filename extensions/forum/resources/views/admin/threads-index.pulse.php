<?php

declare(strict_types=1);

/**
 * Admin thread list with pagination.
 *
 * @var list<array{id: string, tenant_id: ?string, category_id: string, author_id: string, title: string, slug: string, type: string, status: string, is_pinned: bool, is_locked: bool, solved_post_id: ?string, reply_count: int, view_count: int, vote_score: int, last_activity_at: ?string, created_at: string, updated_at: string}> $data
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $pagination
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lastPage = $pagination['last_page'] ?? 1;
$currentPage = $pagination['current_page'] ?? 1;
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.threads.title"><?= @t('forum.admin.threads.title') ?></h1>
    <span style="color:var(--color-text-disabled);font-size:0.875rem"><?= (int) ($pagination['total'] ?? 0) ?> <?= @t('forum.admin.total') ?></span>
</div>

<div class="forum-card">
    <?php if ($data === []): ?>
        <div class="forum-empty">
            <h3 data-t="forum.admin.threads.no_threads"><?= @t('forum.admin.threads.no_threads') ?></h3>
            <p data-t="forum.admin.threads.no_threads_hint"><?= @t('forum.admin.threads.no_threads_hint') ?></p>
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse" role="table">
            <thead>
                <tr style="border-bottom:2px solid var(--color-border)">
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.threads.table.title"><?= @t('forum.admin.threads.table.title') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.threads.table.status"><?= @t('forum.admin.threads.table.status') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.threads.table.flags"><?= @t('forum.admin.threads.table.flags') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.threads.table.replies"><?= @t('forum.admin.threads.table.replies') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.threads.table.views"><?= @t('forum.admin.threads.table.views') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.threads.table.votes"><?= @t('forum.admin.threads.table.votes') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.threads.table.created"><?= @t('forum.admin.threads.table.created') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $thread): ?>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <td style="padding:var(--space-2) var(--space-6)">
                            <a href="/admin/forum/threads/<?= $e($thread['id']) ?>" style="font-weight:600"><?= $e($thread['title']) ?></a>
                            <div style="font-size:0.75rem;color:var(--color-text-disabled)"><span data-t="forum.admin.threads.by"><?= @t('forum.admin.threads.by') ?></span> <?= $e($thread['author_id']) ?></div>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6)">
                            <span class="forum-badge forum-badge--<?= $thread['status'] === 'open' ? 'solved' : 'closed' ?>"><?= $e($thread['status']) ?></span>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:center">
                            <?php if ($thread['is_pinned']): ?><span class="forum-badge forum-badge--pinned" title="<?= @t('forum.thread.pinned') ?>" data-t="forum.admin.threads.pin"><?= @t('forum.admin.threads.pin') ?></span><?php endif; ?>
                            <?php if ($thread['is_locked']): ?><span class="forum-badge forum-badge--locked" title="<?= @t('forum.thread.locked') ?>" data-t="forum.admin.threads.lock"><?= @t('forum.admin.threads.lock') ?></span><?php endif; ?>
                            <?php if ($thread['solved_post_id'] !== null): ?><span class="forum-badge forum-badge--solved" title="<?= @t('forum.thread.solved') ?>" data-t="forum.thread.solved"><?= @t('forum.thread.solved') ?></span><?php endif; ?>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;color:var(--color-text-muted)"><?= (int) $thread['reply_count'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;color:var(--color-text-muted)"><?= (int) $thread['view_count'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:600"><?= (int) $thread['vote_score'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right">
                            <time datetime="<?= $e($thread['created_at']) ?>" style="font-size:0.8125rem;color:var(--color-text-disabled)"><?= $e($thread['created_at']) ?></time>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($lastPage > 1): ?>
    <nav class="forum-pagination" aria-label="<?= @t('forum.pagination.label') ?>">
        <?php if ($currentPage > 1): ?>
            <a href="?page=<?= $currentPage - 1 ?>" aria-label="<?= @t('forum.pagination.previous') ?>">&laquo;</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $lastPage; $i++): ?>
            <?php if ($i === $currentPage): ?>
                <span class="active" aria-current="page"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($currentPage < $lastPage): ?>
            <a href="?page=<?= $currentPage + 1 ?>" aria-label="<?= @t('forum.pagination.next') ?>">&raquo;</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
