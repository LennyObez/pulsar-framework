<?php

declare(strict_types=1);

/**
 * Admin tag detail.
 *
 * @var array{id: string, slug: string, name: string, description: ?string, usage_count: int} $tag
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1>Tag: <?= $e($tag['name']) ?></h1>
    <a href="/admin/forum/tags" class="forum-btn forum-btn--ghost forum-btn--sm">&larr; Back</a>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--space-8)">
    <div class="forum-card">
        <div class="forum-card-header"><h2>Edit Tag</h2></div>
        <div class="forum-card-body">
            <form method="post" action="/admin/forum/tags/<?= $e($tag['id']) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="_method" value="PUT">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div class="forum-form-group">
                        <label for="tag-name" class="forum-label">Name</label>
                        <input type="text" id="tag-name" name="name" class="forum-input" value="<?= $e($tag['name']) ?>" required>
                    </div>
                    <div class="forum-form-group">
                        <label for="tag-slug" class="forum-label">Slug</label>
                        <input type="text" id="tag-slug" name="slug" class="forum-input" value="<?= $e($tag['slug']) ?>" required pattern="[a-z0-9\-]+">
                    </div>
                </div>
                <div class="forum-form-group">
                    <label for="tag-desc" class="forum-label">Description</label>
                    <input type="text" id="tag-desc" name="description" class="forum-input" value="<?= $e($tag['description'] ?? '') ?>">
                </div>
                <button type="submit" class="forum-btn forum-btn--primary">Update</button>
            </form>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-6)">
        <div class="forum-card">
            <div class="forum-card-header"><h3>Stats</h3></div>
            <div class="forum-card-body">
                <div class="forum-stat-card" style="text-align:center">
                    <div class="forum-stat-value"><?= (int) $tag['usage_count'] ?></div>
                    <div class="forum-stat-label">Threads using this tag</div>
                </div>
            </div>
        </div>
        <div class="forum-card">
            <div class="forum-card-header"><h3>Actions</h3></div>
            <div class="forum-card-body">
                <form method="post" action="/admin/forum/tags/<?= $e($tag['id']) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="forum-btn forum-btn--danger" style="width:100%" onclick="return confirm('Delete this tag?')">Delete Tag</button>
                </form>
            </div>
        </div>
    </div>
</div>
