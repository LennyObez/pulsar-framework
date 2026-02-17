<?php

declare(strict_types=1);

/**
 * Category page: threads in a category with pagination.
 *
 * @var array{id: string, slug: string, name: string, description: string, is_locked: bool} $category
 * @var list<array{id: string, title: string, slug: string, author_id: string, status: string, type: string, is_pinned: bool, is_locked: bool, is_solved: bool, reply_count: int, view_count: int, vote_score: int, created_at: string, last_activity_at: ?string}> $threads
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $pagination
 * @var int    $page
 * @var string $page_title
 * @var bool   $__is_authenticated
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lastPage = $pagination['last_page'] ?? 1;
$currentPage = $pagination['current_page'] ?? $page;
$total = $pagination['total'] ?? 0;
?>

<nav class="forum-breadcrumbs" aria-label="<?= @t('forum.breadcrumb.label') ?>">
    <a href="/forum"><?= @t('forum.breadcrumb.forum') ?></a>
    <span class="separator" aria-hidden="true">/</span>
    <span aria-current="page"><?= $e($category['name']) ?></span>
</nav>

<div class="forum-page-title">
    <div>
        <h1><?= $e($category['name']) ?></h1>
        <?php if ($category['description'] !== ''): ?>
            <p style="color:var(--color-text-muted);font-size:0.875rem;margin-top:var(--space-1)"><?= $e($category['description']) ?></p>
        <?php endif; ?>
    </div>
    <?php if (($__is_authenticated ?? false) && !$category['is_locked']): ?>
        <a href="/forum/new-thread?category=<?= $e($category['slug']) ?>" class="forum-btn forum-btn--primary"><?= @t('forum.nav.new_thread') ?></a>
    <?php endif; ?>
</div>

<?php if ($category['is_locked']): ?>
    <div class="forum-alert forum-alert--info" role="status">
        <?= @t('forum.category.locked_notice') ?>
    </div>
<?php endif; ?>

<div class="forum-card">
    <?php if ($threads === []): ?>
        <div class="forum-empty">
            <div class="forum-empty-icon" aria-hidden="true">&#128196;</div>
            <h3><?= @t('forum.category.no_threads') ?></h3>
            <p><?= @t('forum.category.no_threads_hint') ?></p>
        </div>
    <?php else: ?>
        <ul class="forum-thread-list" role="list">
            <?php foreach ($threads as $thread): ?>
                <li class="forum-thread-item<?= $thread['is_pinned'] ? ' pinned' : '' ?><?= $thread['is_solved'] ? ' solved' : '' ?>">
                    <div class="forum-thread-votes">
                        <span class="forum-thread-vote-count"><?= (int) $thread['vote_score'] ?></span>
                        <span class="forum-thread-vote-label"><?= @t('forum.thread.votes', ['count' => (int) $thread['vote_score']]) ?></span>
                    </div>
                    <div class="forum-thread-content">
                        <div class="forum-thread-title">
                            <a href="/forum/t/<?= $e($thread['slug']) ?>"><?= $e($thread['title']) ?></a>
                            <?php if ($thread['is_pinned']): ?>
                                <span class="forum-badge forum-badge--pinned"><?= @t('forum.thread.pinned') ?></span>
                            <?php endif; ?>
                            <?php if ($thread['is_locked']): ?>
                                <span class="forum-badge forum-badge--locked"><?= @t('forum.thread.locked') ?></span>
                            <?php endif; ?>
                            <?php if ($thread['is_solved']): ?>
                                <span class="forum-badge forum-badge--solved"><?= @t('forum.thread.solved') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="forum-thread-meta">
                            <span><?= $e($thread['type']) ?></span>
                            <span>&middot;</span>
                            <time datetime="<?= $e($thread['created_at']) ?>"><?= $e($thread['created_at']) ?></time>
                            <?php if ($thread['last_activity_at'] !== null): ?>
                                <span>&middot;</span>
                                <span><?= @t('forum.thread.active') ?> <time datetime="<?= $e($thread['last_activity_at']) ?>"><?= $e($thread['last_activity_at']) ?></time></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="forum-thread-stats">
                        <span class="forum-thread-stat" title="<?= @t('forum.thread.replies', ['count' => (int) $thread['reply_count']]) ?>">&#128172; <?= (int) $thread['reply_count'] ?></span>
                        <span class="forum-thread-stat" title="<?= @t('forum.thread.views', ['count' => (int) $thread['view_count']]) ?>">&#128065; <?= (int) $thread['view_count'] ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
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

<p style="text-align:center;color:var(--color-text-disabled);font-size:0.8125rem;margin-top:var(--space-4)">
    <?= @t('forum.category.threads_total', ['count' => $total]) ?>
</p>
