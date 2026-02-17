<?php

declare(strict_types=1);

/**
 * User profile page: reputation, badges, post history.
 *
 * @var array{user_id: string, reputation_score: int, reputation_level: string, post_count: int, thread_count: int, is_banned: bool, created_at: string} $profile
 * @var list<array{id: string, title: string, slug: string, reply_count: int, created_at: string}> $threads
 * @var list<array{id: string, thread_id: string, body_html: string, vote_score: int, created_at: string}> $posts
 * @var list<array{badge: object, awarded_at: string}> $badges
 * @var string $page_title
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<nav class="forum-breadcrumbs" aria-label="<?= @t('forum.breadcrumb.label') ?>">
    <a href="/forum"><?= @t('forum.breadcrumb.forum') ?></a>
    <span class="separator" aria-hidden="true">/</span>
    <span aria-current="page"><?= @t('forum.profile.title') ?></span>
</nav>

<div class="forum-profile-header">
    <div class="forum-profile-avatar" aria-hidden="true">
        <?= strtoupper(substr($profile['user_id'], 0, 1)) ?>
    </div>
    <div class="forum-profile-info">
        <h1><?= $e($profile['user_id']) ?></h1>
        <div class="forum-profile-meta">
            <span class="forum-reputation-badge"><?= $e(ucfirst($profile['reputation_level'])) ?></span>
            <span>&middot;</span>
            <span><?= @t('forum.profile.joined') ?> <time datetime="<?= $e($profile['created_at']) ?>"><?= $e($profile['created_at']) ?></time></span>
            <?php if ($profile['is_banned']): ?>
                <span>&middot;</span>
                <span class="forum-badge forum-badge--locked"><?= @t('forum.profile.banned') ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="forum-stat-grid" style="margin-bottom:var(--space-8)">
    <div class="forum-stat-card">
        <div class="forum-stat-value"><?= (int) $profile['reputation_score'] ?></div>
        <div class="forum-stat-label"><?= @t('forum.profile.reputation') ?></div>
    </div>
    <div class="forum-stat-card">
        <div class="forum-stat-value"><?= (int) $profile['thread_count'] ?></div>
        <div class="forum-stat-label"><?= @t('forum.profile.threads') ?></div>
    </div>
    <div class="forum-stat-card">
        <div class="forum-stat-value"><?= (int) $profile['post_count'] ?></div>
        <div class="forum-stat-label"><?= @t('forum.profile.posts') ?></div>
    </div>
    <div class="forum-stat-card">
        <div class="forum-stat-value"><?= count($badges) ?></div>
        <div class="forum-stat-label"><?= @t('forum.profile.badges') ?></div>
    </div>
</div>

<?php if ($badges !== []): ?>
    <div class="forum-card" style="margin-bottom:var(--space-8)">
        <div class="forum-card-header">
            <h2><?= @t('forum.profile.badges') ?></h2>
        </div>
        <div class="forum-card-body" style="display:flex;flex-wrap:wrap;gap:var(--space-2)">
            <?php foreach ($badges as $b): ?>
                <span class="forum-badge forum-badge--moderator" title="<?= $e($b['awarded_at']) ?>">
                    <?= $e(is_object($b['badge']) ? $b['badge']->value : (string) $b['badge']) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="forum-grid forum-grid--sidebar">
    <div class="forum-main">
        <div class="forum-card">
            <div class="forum-card-header">
                <h2><?= @t('forum.profile.recent_threads') ?></h2>
            </div>
            <?php if ($threads === []): ?>
                <div class="forum-card-body">
                    <p class="forum-empty" style="padding:var(--space-4)"><?= @t('forum.profile.no_threads') ?></p>
                </div>
            <?php else: ?>
                <ul class="forum-thread-list" role="list">
                    <?php foreach ($threads as $thread): ?>
                        <li class="forum-thread-item">
                            <div class="forum-thread-content">
                                <div class="forum-thread-title">
                                    <a href="/forum/t/<?= $e($thread['slug']) ?>"><?= $e($thread['title']) ?></a>
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
    </div>

    <aside class="forum-sidebar">
        <div class="forum-card">
            <div class="forum-card-header">
                <h3><?= @t('forum.profile.recent_posts') ?></h3>
            </div>
            <?php if ($posts === []): ?>
                <div class="forum-card-body">
                    <p class="forum-empty" style="padding:var(--space-4)"><?= @t('forum.profile.no_posts') ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <div style="padding:var(--space-4) var(--space-6);border-bottom:1px solid var(--color-border)">
                        <div style="font-size:0.8125rem;color:var(--color-text-disabled);margin-bottom:var(--space-1)">
                            <time datetime="<?= $e($post['created_at']) ?>"><?= $e($post['created_at']) ?></time>
                            &middot; <?= @t('forum.thread.votes', ['count' => (int) $post['vote_score']]) ?>
                        </div>
                        <div class="forum-post-content" style="font-size:0.875rem;max-height:80px;overflow:hidden">
                            <?= $post['body_html'] ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
