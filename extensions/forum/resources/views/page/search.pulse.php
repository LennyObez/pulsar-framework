<?php

declare(strict_types=1);

/**
 * Search page: full-text search across threads.
 *
 * @var string $query
 * @var list<array{id: string, title: string, slug: string, status: string, reply_count: int, view_count: int, vote_score: int, created_at: string}> $threads
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $pagination
 * @var int    $page
 * @var string $page_title
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lastPage = $pagination['last_page'] ?? 1;
$currentPage = $pagination['current_page'] ?? $page;
$total = $pagination['total'] ?? 0;
?>

<nav class="forum-breadcrumbs" aria-label="<?= @t('forum.breadcrumb.label') ?>">
    <a href="/forum"><?= @t('forum.breadcrumb.forum') ?></a>
    <span class="separator" aria-hidden="true">/</span>
    <span aria-current="page"><?= @t('forum.search.title') ?></span>
</nav>

<div class="forum-page-title">
    <h1><?= @t('forum.search.title') ?></h1>
</div>

<form method="get" action="/forum/search" class="forum-search" role="search" style="margin-bottom:var(--space-8)">
    <label for="search-q" class="sr-only"><?= @t('forum.search.label') ?></label>
    <input type="search" id="search-q" name="q" class="forum-input" value="<?= $e($query) ?>" placeholder="<?= @t('forum.search.placeholder') ?>" aria-label="<?= @t('forum.search.label') ?>" autofocus>
    <button type="submit" class="forum-btn forum-btn--primary"><?= @t('forum.search.submit') ?></button>
</form>

<?php if ($query !== ''): ?>
    <p style="color:var(--color-text-muted);font-size:0.875rem;margin-bottom:var(--space-6)">
        <?= @t('forum.search.results_count', ['total' => $total, 'query' => $e($query)]) ?>
    </p>
<?php endif; ?>

<?php if ($query !== '' && $threads === []): ?>
    <div class="forum-empty">
        <div class="forum-empty-icon" aria-hidden="true">&#128269;</div>
        <h3><?= @t('forum.search.no_results') ?></h3>
        <p><?= @t('forum.search.no_results_hint') ?></p>
    </div>
<?php elseif ($threads !== []): ?>
    <div class="forum-card">
        <ul class="forum-thread-list" role="list">
            <?php foreach ($threads as $thread): ?>
                <li class="forum-thread-item">
                    <div class="forum-thread-votes">
                        <span class="forum-thread-vote-count"><?= (int) $thread['vote_score'] ?></span>
                        <span class="forum-thread-vote-label"><?= @t('forum.thread.votes', ['count' => (int) $thread['vote_score']]) ?></span>
                    </div>
                    <div class="forum-thread-content">
                        <div class="forum-thread-title">
                            <a href="/forum/t/<?= $e($thread['slug']) ?>"><?= $e($thread['title']) ?></a>
                        </div>
                        <div class="forum-thread-meta">
                            <span><?= @t('forum.thread.replies', ['count' => (int) $thread['reply_count']]) ?></span>
                            <span>&middot;</span>
                            <span><?= @t('forum.thread.views', ['count' => (int) $thread['view_count']]) ?></span>
                            <span>&middot;</span>
                            <time datetime="<?= $e($thread['created_at']) ?>"><?= $e($thread['created_at']) ?></time>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($lastPage > 1): ?>
        <nav class="forum-pagination" aria-label="<?= @t('forum.pagination.label') ?>">
            <?php if ($currentPage > 1): ?>
                <a href="?q=<?= $e(urlencode($query)) ?>&page=<?= $currentPage - 1 ?>" aria-label="<?= @t('forum.pagination.previous') ?>">&laquo;</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $lastPage; $i++): ?>
                <?php if ($i === $currentPage): ?>
                    <span class="active" aria-current="page"><?= $i ?></span>
                <?php else: ?>
                    <a href="?q=<?= $e(urlencode($query)) ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($currentPage < $lastPage): ?>
                <a href="?q=<?= $e(urlencode($query)) ?>&page=<?= $currentPage + 1 ?>" aria-label="<?= @t('forum.pagination.next') ?>">&raquo;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
