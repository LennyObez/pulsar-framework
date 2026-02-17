<?php

declare(strict_types=1);

/**
 * Forum homepage: category listing with recent threads sidebar.
 *
 * @var list<array{id: string, slug: string, name: string, description: string, is_locked: bool, sort_order: int}> $categories
 * @var list<array{id: string, title: string, slug: string, status: string, reply_count: int, view_count: int, vote_score: int, is_pinned: bool, created_at: string, last_activity_at: ?string}> $recent_threads
 * @var int    $total_threads
 * @var string $page_title
 * @var bool   $__is_authenticated
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<div class="forum-page-title">
    <h1><?= @t('forum.home.title') ?></h1>
    <?php if ($__is_authenticated ?? false): ?>
        <a href="/forum/new-thread" class="forum-btn forum-btn--primary"><?= @t('forum.nav.new_thread') ?></a>
    <?php endif; ?>
</div>

<div class="forum-grid forum-grid--sidebar">
    <div class="forum-main">
        <div class="forum-card">
            <div class="forum-card-header">
                <h2><?= @t('forum.home.categories') ?></h2>
            </div>
            <?php if ($categories === []): ?>
                <div class="forum-empty">
                    <div class="forum-empty-icon" aria-hidden="true">&#128193;</div>
                    <h3><?= @t('forum.home.no_categories') ?></h3>
                    <p><?= @t('forum.home.no_categories_hint') ?></p>
                </div>
            <?php else: ?>
                <ul class="forum-category-list" role="list">
                    <?php foreach ($categories as $cat): ?>
                        <li class="forum-category-item">
                            <div class="forum-category-icon" aria-hidden="true">
                                <?php if ($cat['is_locked']): ?>&#128274;<?php else: ?>&#128172;<?php endif; ?>
                            </div>
                            <div class="forum-category-info">
                                <a href="/forum/c/<?= $e($cat['slug']) ?>" class="forum-category-name">
                                    <?= $e($cat['name']) ?>
                                </a>
                                <?php if ($cat['description'] !== ''): ?>
                                    <p class="forum-category-desc"><?= $e($cat['description']) ?></p>
                                <?php endif; ?>
                                <?php if ($cat['is_locked']): ?>
                                    <span class="forum-badge forum-badge--locked"><?= @t('forum.category.locked') ?></span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <aside class="forum-sidebar">
        <div class="forum-card">
            <div class="forum-card-header">
                <h3><?= @t('forum.home.forum_stats') ?></h3>
            </div>
            <div class="forum-card-body">
                <div class="forum-stat-grid">
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= (int) $total_threads ?></div>
                        <div class="forum-stat-label"><?= @t('forum.home.threads_count') ?></div>
                    </div>
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= count($categories) ?></div>
                        <div class="forum-stat-label"><?= @t('forum.home.categories_count') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="forum-card">
            <div class="forum-card-header">
                <h3><?= @t('forum.home.recent_threads') ?></h3>
            </div>
            <?php if ($recent_threads === []): ?>
                <div class="forum-card-body">
                    <p class="forum-empty" style="padding:var(--space-4)"><?= @t('forum.home.no_threads') ?></p>
                </div>
            <?php else: ?>
                <ul class="forum-thread-list" role="list">
                    <?php foreach ($recent_threads as $thread): ?>
                        <li class="forum-thread-item<?= $thread['is_pinned'] ? ' pinned' : '' ?>">
                            <div class="forum-thread-content">
                                <div class="forum-thread-title">
                                    <a href="/forum/t/<?= $e($thread['slug']) ?>"><?= $e($thread['title']) ?></a>
                                    <?php if ($thread['is_pinned']): ?>
                                        <span class="forum-badge forum-badge--pinned"><?= @t('forum.thread.pinned') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="forum-thread-meta">
                                    <span><?= @t('forum.thread.replies', ['count' => (int) $thread['reply_count']]) ?></span>
                                    <span>&middot;</span>
                                    <time datetime="<?= $e($thread['created_at']) ?>"><?= $e($thread['created_at']) ?></time>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>
