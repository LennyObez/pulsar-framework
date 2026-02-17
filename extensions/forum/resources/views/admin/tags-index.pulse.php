<?php

declare(strict_types=1);

/**
 * Admin tag list with CRUD.
 *
 * @var list<array{id: string, slug: string, name: string, description: ?string, usage_count: int}> $data
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1>Tags</h1>
    <button type="button" class="forum-btn forum-btn--primary" onclick="document.getElementById('create-tag-form').toggleAttribute('hidden')">New Tag</button>
</div>

<div class="forum-card" id="create-tag-form" hidden style="margin-bottom:var(--space-8)">
    <div class="forum-card-header"><h2>Create Tag</h2></div>
    <div class="forum-card-body">
        <form method="post" action="/admin/forum/tags">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                <div class="forum-form-group">
                    <label for="tag-name" class="forum-label">Name</label>
                    <input type="text" id="tag-name" name="name" class="forum-input" required>
                </div>
                <div class="forum-form-group">
                    <label for="tag-slug" class="forum-label">Slug</label>
                    <input type="text" id="tag-slug" name="slug" class="forum-input" required pattern="[a-z0-9\-]+">
                </div>
            </div>
            <div class="forum-form-group">
                <label for="tag-desc" class="forum-label">Description</label>
                <input type="text" id="tag-desc" name="description" class="forum-input">
            </div>
            <button type="submit" class="forum-btn forum-btn--primary">Create</button>
        </form>
    </div>
</div>

<div class="forum-card">
    <?php if ($data === []): ?>
        <div class="forum-empty">
            <h3>No tags</h3>
            <p>Create your first tag.</p>
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse" role="table">
            <thead>
                <tr style="border-bottom:2px solid var(--color-border)">
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Name</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Slug</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Description</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Usage</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $tag): ?>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <td style="padding:var(--space-2) var(--space-6);font-weight:600"><?= $e($tag['name']) ?></td>
                        <td style="padding:var(--space-2) var(--space-6);font-family:var(--font-mono);font-size:0.8125rem;color:var(--color-text-muted)"><?= $e($tag['slug']) ?></td>
                        <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem"><?= $e($tag['description'] ?? '') ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;color:var(--color-text-muted)"><?= (int) $tag['usage_count'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right">
                            <a href="/admin/forum/tags/<?= $e($tag['id']) ?>" class="forum-btn forum-btn--ghost forum-btn--sm">Edit</a>
                            <form method="post" action="/admin/forum/tags/<?= $e($tag['id']) ?>" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="forum-btn forum-btn--danger forum-btn--sm" onclick="return confirm('Delete this tag?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
