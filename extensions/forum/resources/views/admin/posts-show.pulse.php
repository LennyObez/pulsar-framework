<?php

declare(strict_types=1);

/**
 * Admin single post view.
 *
 * @var array{id: string, thread_id: string, parent_id: ?string, author_id: string, body: string, body_html: string, is_solution: bool, vote_score: int, edit_count: int, edited_by: ?string, edited_at: ?string, is_deleted: bool, created_at: string, updated_at: string} $post
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.posts.detail"><?= @t('forum.admin.posts.detail') ?></h1>
    <a href="/admin/forum/threads/<?= $e($post['thread_id']) ?>/posts" class="forum-btn forum-btn--ghost forum-btn--sm">&larr; <span data-t="forum.admin.posts.back_to_posts"><?= @t('forum.admin.posts.back_to_posts') ?></span></a>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--space-8)">
    <div class="forum-card">
        <div class="forum-card-header">
            <h2 data-t="forum.admin.posts.content"><?= @t('forum.admin.posts.content') ?></h2>
            <?php if ($post['is_solution']): ?>
                <span class="forum-solution-marker" data-t="forum.post.accepted_solution"><?= @t('forum.post.accepted_solution') ?></span>
            <?php endif; ?>
        </div>
        <div class="forum-card-body">
            <div class="forum-post-content"><?= $post['body_html'] ?></div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-6)">
        <div class="forum-card">
            <div class="forum-card-header"><h3 data-t="forum.admin.posts.metadata"><?= @t('forum.admin.posts.metadata') ?></h3></div>
            <div class="forum-card-body">
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:var(--space-1) var(--space-4);font-size:0.875rem">
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.posts.field.author"><?= @t('forum.admin.posts.field.author') ?></dt>
                    <dd><a href="/admin/forum/users/<?= $e($post['author_id']) ?>"><?= $e($post['author_id']) ?></a></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.posts.field.votes"><?= @t('forum.admin.posts.field.votes') ?></dt>
                    <dd style="font-weight:700"><?= (int) $post['vote_score'] ?></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.posts.field.edits"><?= @t('forum.admin.posts.field.edits') ?></dt>
                    <dd><?= (int) $post['edit_count'] ?></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.posts.field.created"><?= @t('forum.admin.posts.field.created') ?></dt>
                    <dd><time datetime="<?= $e($post['created_at']) ?>"><?= $e($post['created_at']) ?></time></dd>
                </dl>
            </div>
        </div>

        <div class="forum-card">
            <div class="forum-card-header"><h3 data-t="forum.admin.actions"><?= @t('forum.admin.actions') ?></h3></div>
            <div class="forum-card-body" style="display:flex;flex-direction:column;gap:var(--space-2)">
                <form method="post" action="/admin/forum/posts/<?= $e($post['id']) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="forum-btn forum-btn--danger" style="width:100%" data-t="forum.admin.posts.delete_post" onclick="return confirm('<?= @t('forum.admin.posts.confirm_delete') ?>')"><?= @t('forum.admin.posts.delete_post') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
