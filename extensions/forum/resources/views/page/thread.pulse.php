<?php

declare(strict_types=1);

/**
 * Thread page: original post and paginated replies with voting.
 *
 * @var array{id: string, title: string, slug: string, author_id: string, category_id: string, type: string, status: string, is_pinned: bool, is_locked: bool, is_solved: bool, solved_post_id: ?string, reply_count: int, view_count: int, vote_score: int, created_at: string, last_activity_at: ?string} $thread
 * @var list<array{id: string, author_id: string, parent_id: ?string, body_html: string, body: string, is_solution: bool, vote_score: int, edit_count: int, created_at: string, edited_at: ?string}> $posts
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $pagination
 * @var int    $page
 * @var string $page_title
 * @var bool   $__is_authenticated
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lastPage = $pagination['last_page'] ?? 1;
$currentPage = $pagination['current_page'] ?? $page;
$isAuth = $__is_authenticated ?? false;
?>

<nav class="forum-breadcrumbs" aria-label="<?= @t('forum.breadcrumb.label') ?>">
    <a href="/forum"><?= @t('forum.breadcrumb.forum') ?></a>
    <span class="separator" aria-hidden="true">/</span>
    <span aria-current="page"><?= $e($thread['title']) ?></span>
</nav>

<article>
    <div class="forum-page-title">
        <div>
            <h1 style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap">
                <?= $e($thread['title']) ?>
                <?php if ($thread['is_pinned']): ?>
                    <span class="forum-badge forum-badge--pinned"><?= @t('forum.thread.pinned') ?></span>
                <?php endif; ?>
                <?php if ($thread['is_locked']): ?>
                    <span class="forum-badge forum-badge--locked"><?= @t('forum.thread.locked') ?></span>
                <?php endif; ?>
                <?php if ($thread['is_solved']): ?>
                    <span class="forum-badge forum-badge--solved"><?= @t('forum.thread.solved') ?></span>
                <?php endif; ?>
            </h1>
            <div class="forum-thread-meta" style="margin-top:var(--space-2)">
                <span><?= @t('forum.thread.by', ['author' => $e($thread['author_id'])]) ?></span>
                <span>&middot;</span>
                <time datetime="<?= $e($thread['created_at']) ?>"><?= $e($thread['created_at']) ?></time>
                <span>&middot;</span>
                <span><?= @t('forum.thread.views', ['count' => (int) $thread['view_count']]) ?></span>
                <span>&middot;</span>
                <span><?= @t('forum.thread.replies', ['count' => (int) $thread['reply_count']]) ?></span>
                <span>&middot;</span>
                <span><?= @t('forum.thread.votes', ['count' => (int) $thread['vote_score']]) ?></span>
            </div>
        </div>
    </div>

    <div class="forum-card">
        <?php if ($posts === []): ?>
            <div class="forum-empty">
                <h3><?= @t('forum.thread.no_posts') ?></h3>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $idx => $post): ?>
                <div class="forum-post<?= $post['is_solution'] ? ' solution' : '' ?>" id="post-<?= $e($post['id']) ?>">
                    <div class="forum-post-sidebar">
                        <div class="forum-post-avatar" aria-hidden="true">
                            <?= strtoupper(substr($post['author_id'], 0, 1)) ?>
                        </div>
                        <div class="forum-post-vote-controls">
                            <?php if ($isAuth): ?>
                                <button type="button" class="forum-vote-btn" aria-label="<?= @t('forum.post.upvote') ?>" data-action="upvote" data-post-id="<?= $e($post['id']) ?>">&#9650;</button>
                            <?php endif; ?>
                            <span class="forum-vote-score"><?= (int) $post['vote_score'] ?></span>
                            <?php if ($isAuth): ?>
                                <button type="button" class="forum-vote-btn" aria-label="<?= @t('forum.post.downvote') ?>" data-action="downvote" data-post-id="<?= $e($post['id']) ?>">&#9660;</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="forum-post-body">
                        <div class="forum-post-header">
                            <span class="forum-post-author"><?= $e($post['author_id']) ?></span>
                            <span class="forum-post-date">
                                <time datetime="<?= $e($post['created_at']) ?>"><?= $e($post['created_at']) ?></time>
                                <?php if ($post['edit_count'] > 0): ?>
                                    <span title="<?= @t('forum.post.edited_times', ['count' => (int) $post['edit_count']]) ?>"><?= @t('forum.post.edited') ?></span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php if ($post['is_solution']): ?>
                            <div class="forum-solution-marker" aria-label="<?= @t('forum.post.accepted_solution') ?>">&#10003; <?= @t('forum.post.accepted_solution') ?></div>
                        <?php endif; ?>

                        <div class="forum-post-content">
                            <?= $post['body_html'] ?>
                        </div>

                        <div class="forum-post-actions">
                            <?php if ($isAuth && !$thread['is_locked']): ?>
                                <button type="button" class="forum-post-action" data-action="reply" data-post-id="<?= $e($post['id']) ?>"><?= @t('forum.post.reply') ?></button>
                                <button type="button" class="forum-post-action" data-action="report" data-post-id="<?= $e($post['id']) ?>"><?= @t('forum.post.report') ?></button>
                            <?php endif; ?>
                            <a href="#post-<?= $e($post['id']) ?>" class="forum-post-action"><?= @t('forum.post.link') ?></a>
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

    <?php if ($isAuth && !$thread['is_locked']): ?>
        <div class="forum-reply-editor" id="reply-editor">
            <h3><?= @t('forum.post.post_reply') ?></h3>
            <form method="post" action="/forum/t/<?= $e($thread['slug']) ?>/reply">
                <input type="hidden" name="_csrf" value="<?= $e($__csrf_token ?? '') ?>">
                <div class="forum-form-group">
                    <label for="reply-body" class="forum-label"><?= @t('forum.post.your_reply') ?></label>
                    <textarea id="reply-body" name="body" class="forum-textarea" rows="6" required aria-required="true" placeholder="<?= @t('forum.post.reply_placeholder') ?>"></textarea>
                    <span class="forum-form-hint"><?= @t('forum.post.markdown_hint') ?></span>
                </div>
                <button type="submit" class="forum-btn forum-btn--primary"><?= @t('forum.post.submit_reply') ?></button>
            </form>
        </div>
    <?php elseif ($thread['is_locked']): ?>
        <div class="forum-alert forum-alert--info" role="status" style="margin-top:var(--space-6)">
            <?= @t('forum.thread.locked_notice') ?>
        </div>
    <?php else: ?>
        <div class="forum-alert forum-alert--info" style="margin-top:var(--space-6)">
            <a href="/forum/login"><?= @t('forum.nav.sign_in') ?></a> <?= @t('forum.thread.sign_in_to_reply') ?>
        </div>
    <?php endif; ?>
</article>
