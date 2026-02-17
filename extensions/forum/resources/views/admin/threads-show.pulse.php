<?php

declare(strict_types=1);

/**
 * Admin thread detail view with moderation actions.
 *
 * @var array{id: string, tenant_id: ?string, category_id: string, author_id: string, title: string, slug: string, type: string, status: string, is_pinned: bool, is_locked: bool, solved_post_id: ?string, reply_count: int, view_count: int, vote_score: int, last_activity_at: ?string, created_at: string, updated_at: string} $thread
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1><?= $e($thread['title']) ?></h1>
    <a href="/admin/forum/threads" class="forum-btn forum-btn--ghost forum-btn--sm">&larr; <span data-t="forum.admin.threads.back"><?= @t('forum.admin.threads.back') ?></span></a>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--space-8)">
    <div class="forum-card">
        <div class="forum-card-header">
            <h2 data-t="forum.admin.threads.details"><?= @t('forum.admin.threads.details') ?></h2>
        </div>
        <div class="forum-card-body">
            <dl style="display:grid;grid-template-columns:auto 1fr;gap:var(--space-2) var(--space-6)">
                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.id"><?= @t('forum.admin.threads.field.id') ?></dt>
                <dd style="font-family:var(--font-mono);font-size:0.8125rem"><?= $e($thread['id']) ?></dd>

                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.author"><?= @t('forum.admin.threads.field.author') ?></dt>
                <dd><a href="/admin/forum/users/<?= $e($thread['author_id']) ?>"><?= $e($thread['author_id']) ?></a></dd>

                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.category"><?= @t('forum.admin.threads.field.category') ?></dt>
                <dd><?= $e($thread['category_id']) ?></dd>

                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.type"><?= @t('forum.admin.threads.field.type') ?></dt>
                <dd><?= $e($thread['type']) ?></dd>

                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.status"><?= @t('forum.admin.threads.field.status') ?></dt>
                <dd><span class="forum-badge forum-badge--<?= $thread['status'] === 'open' ? 'solved' : 'closed' ?>"><?= $e($thread['status']) ?></span></dd>

                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.slug"><?= @t('forum.admin.threads.field.slug') ?></dt>
                <dd style="font-family:var(--font-mono);font-size:0.8125rem"><?= $e($thread['slug']) ?></dd>

                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.created"><?= @t('forum.admin.threads.field.created') ?></dt>
                <dd><time datetime="<?= $e($thread['created_at']) ?>"><?= $e($thread['created_at']) ?></time></dd>

                <dt style="color:var(--color-text-disabled);font-size:0.875rem" data-t="forum.admin.threads.field.updated"><?= @t('forum.admin.threads.field.updated') ?></dt>
                <dd><time datetime="<?= $e($thread['updated_at']) ?>"><?= $e($thread['updated_at']) ?></time></dd>
            </dl>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-6)">
        <div class="forum-card">
            <div class="forum-card-header"><h3 data-t="forum.admin.threads.stats"><?= @t('forum.admin.threads.stats') ?></h3></div>
            <div class="forum-card-body">
                <div class="forum-stat-grid">
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= (int) $thread['reply_count'] ?></div>
                        <div class="forum-stat-label" data-t="forum.profile.threads"><?= @t('forum.thread.replies', ['count' => '']) ?></div>
                    </div>
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= (int) $thread['view_count'] ?></div>
                        <div class="forum-stat-label" data-t="forum.admin.threads.table.views"><?= @t('forum.admin.threads.table.views') ?></div>
                    </div>
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= (int) $thread['vote_score'] ?></div>
                        <div class="forum-stat-label" data-t="forum.admin.threads.table.votes"><?= @t('forum.admin.threads.table.votes') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="forum-card">
            <div class="forum-card-header"><h3 data-t="forum.admin.actions"><?= @t('forum.admin.actions') ?></h3></div>
            <div class="forum-card-body" style="display:flex;flex-direction:column;gap:var(--space-2)">
                <?php if ($thread['is_locked']): ?>
                    <form method="post" action="/admin/forum/threads/<?= $e($thread['id']) ?>/unlock">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="forum-btn forum-btn--secondary" style="width:100%" data-t="forum.admin.threads.unlock_thread"><?= @t('forum.admin.threads.unlock_thread') ?></button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/admin/forum/threads/<?= $e($thread['id']) ?>/lock">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="forum-btn forum-btn--secondary" style="width:100%" data-t="forum.admin.threads.lock_thread"><?= @t('forum.admin.threads.lock_thread') ?></button>
                    </form>
                <?php endif; ?>

                <?php if ($thread['is_pinned']): ?>
                    <form method="post" action="/admin/forum/threads/<?= $e($thread['id']) ?>/unpin">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="forum-btn forum-btn--secondary" style="width:100%" data-t="forum.admin.threads.unpin_thread"><?= @t('forum.admin.threads.unpin_thread') ?></button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/admin/forum/threads/<?= $e($thread['id']) ?>/pin">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="forum-btn forum-btn--secondary" style="width:100%" data-t="forum.admin.threads.pin_thread"><?= @t('forum.admin.threads.pin_thread') ?></button>
                    </form>
                <?php endif; ?>

                <a href="/admin/forum/threads/<?= $e($thread['id']) ?>/posts" class="forum-btn forum-btn--ghost" style="width:100%;justify-content:center" data-t="forum.admin.threads.view_posts"><?= @t('forum.admin.threads.view_posts') ?></a>

                <form method="post" action="/admin/forum/threads/<?= $e($thread['id']) ?>" style="margin-top:var(--space-4)">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="forum-btn forum-btn--danger" style="width:100%" data-t="forum.admin.threads.delete_thread" onclick="return confirm('<?= @t('forum.admin.threads.confirm_delete') ?>')"><?= @t('forum.admin.threads.delete_thread') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
