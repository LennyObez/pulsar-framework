<?php

declare(strict_types=1);

/**
 * Admin post list for a thread.
 *
 * @var list<array{id: string, thread_id: string, parent_id: ?string, author_id: string, body: string, body_html: string, is_solution: bool, vote_score: int, edit_count: int, edited_by: ?string, edited_at: ?string, is_deleted: bool, created_at: string, updated_at: string}> $data
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $pagination
 * @var string $thread_id
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lastPage = $pagination['last_page'] ?? 1;
$currentPage = $pagination['current_page'] ?? 1;
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.posts.title"><?= @t('forum.admin.posts.title') ?></h1>
    <a href="/admin/forum/threads/<?= $e($thread_id) ?>" class="forum-btn forum-btn--ghost forum-btn--sm">&larr; <span data-t="forum.admin.posts.back"><?= @t('forum.admin.posts.back') ?></span></a>
</div>

<div class="forum-card">
    <?php if ($data === []): ?>
        <div class="forum-empty">
            <h3 data-t="forum.admin.posts.no_posts"><?= @t('forum.admin.posts.no_posts') ?></h3>
        </div>
    <?php else: ?>
        <?php foreach ($data as $post): ?>
            <div class="forum-post<?= $post['is_solution'] ? ' solution' : '' ?><?= $post['is_deleted'] ? ' deleted' : '' ?>" style="<?= $post['is_deleted'] ? 'opacity:0.5' : '' ?>">
                <div class="forum-post-sidebar">
                    <div class="forum-post-avatar" aria-hidden="true"><?= strtoupper(substr($post['author_id'], 0, 1)) ?></div>
                    <span class="forum-vote-score"><?= (int) $post['vote_score'] ?></span>
                </div>
                <div class="forum-post-body">
                    <div class="forum-post-header">
                        <span class="forum-post-author">
                            <a href="/admin/forum/users/<?= $e($post['author_id']) ?>"><?= $e($post['author_id']) ?></a>
                        </span>
                        <span class="forum-post-date">
                            <time datetime="<?= $e($post['created_at']) ?>"><?= $e($post['created_at']) ?></time>
                            <?php if ($post['is_solution']): ?><span class="forum-solution-marker" data-t="forum.post.solution"><?= @t('forum.post.solution') ?></span><?php endif; ?>
                            <?php if ($post['is_deleted']): ?><span class="forum-badge forum-badge--locked" data-t="forum.post.deleted"><?= @t('forum.post.deleted') ?></span><?php endif; ?>
                        </span>
                    </div>
                    <div class="forum-post-content"><?= $post['body_html'] ?></div>
                    <?php if ($post['edit_count'] > 0): ?>
                        <div style="font-size:0.75rem;color:var(--color-text-disabled);margin-top:var(--space-2)">
                            <?= @t('forum.admin.posts.edited_times', ['count' => (int) $post['edit_count']]) ?>
                            <?php if ($post['edited_by'] !== null): ?> <?= @t('forum.admin.posts.edited_by', ['user' => $e($post['edited_by'])]) ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="forum-post-actions">
                        <a href="/admin/forum/posts/<?= $e($post['id']) ?>" class="forum-post-action" data-t="forum.admin.view"><?= @t('forum.admin.view') ?></a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
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
