<?php

declare(strict_types=1);

/**
 * Admin category list.
 *
 * @var list<array{id: string, parent_id: ?string, slug: string, sort_order: int, is_locked: bool, created_at: string, updated_at: string, translations: list<array{id: string, locale: string, name: string, description: string}>}> $data
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.categories.title"><?= @t('forum.admin.categories.title') ?></h1>
    <button type="button" class="forum-btn forum-btn--primary" data-t="forum.admin.categories.new" onclick="document.getElementById('create-category-form').toggleAttribute('hidden')"><?= @t('forum.admin.categories.new') ?></button>
</div>

<div class="forum-card" id="create-category-form" hidden style="margin-bottom:var(--space-8)">
    <div class="forum-card-header"><h2 data-t="forum.admin.categories.create"><?= @t('forum.admin.categories.create') ?></h2></div>
    <div class="forum-card-body">
        <form method="post" action="/admin/forum/categories">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                <div class="forum-form-group">
                    <label for="cat-name" class="forum-label" data-t="forum.admin.categories.field.name"><?= @t('forum.admin.categories.field.name') ?></label>
                    <input type="text" id="cat-name" name="name" class="forum-input" required>
                </div>
                <div class="forum-form-group">
                    <label for="cat-slug" class="forum-label" data-t="forum.admin.categories.field.slug"><?= @t('forum.admin.categories.field.slug') ?></label>
                    <input type="text" id="cat-slug" name="slug" class="forum-input" required pattern="[a-z0-9\-]+">
                </div>
            </div>
            <div class="forum-form-group">
                <label for="cat-desc" class="forum-label" data-t="forum.admin.categories.field.description"><?= @t('forum.admin.categories.field.description') ?></label>
                <textarea id="cat-desc" name="description" class="forum-textarea" rows="2"></textarea>
            </div>
            <div class="forum-form-group">
                <label for="cat-sort" class="forum-label" data-t="forum.admin.categories.field.sort_order"><?= @t('forum.admin.categories.field.sort_order') ?></label>
                <input type="number" id="cat-sort" name="sort_order" class="forum-input" value="0" style="max-width:120px">
            </div>
            <button type="submit" class="forum-btn forum-btn--primary" data-t="forum.admin.create"><?= @t('forum.admin.create') ?></button>
        </form>
    </div>
</div>

<div class="forum-card">
    <?php if ($data === []): ?>
        <div class="forum-empty">
            <h3 data-t="forum.admin.categories.no_categories"><?= @t('forum.admin.categories.no_categories') ?></h3>
            <p data-t="forum.admin.categories.no_categories_hint"><?= @t('forum.admin.categories.no_categories_hint') ?></p>
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse" role="table">
            <thead>
                <tr style="border-bottom:2px solid var(--color-border)">
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.categories.table.name"><?= @t('forum.admin.categories.table.name') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.categories.table.slug"><?= @t('forum.admin.categories.table.slug') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.categories.table.order"><?= @t('forum.admin.categories.table.order') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.categories.table.status"><?= @t('forum.admin.categories.table.status') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.categories.table.locales"><?= @t('forum.admin.categories.table.locales') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.categories.table.actions"><?= @t('forum.admin.categories.table.actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $cat): ?>
                    <?php
                    $name = $cat['slug'];
                    foreach ($cat['translations'] as $t) {
                        if ($t['locale'] === 'en') {
                            $name = $t['name'];
                            break;
                        }
                    }
                    if ($name === $cat['slug'] && $cat['translations'] !== []) {
                        $name = $cat['translations'][0]['name'];
                    }
                    ?>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <td style="padding:var(--space-2) var(--space-6);font-weight:600">
                            <a href="/admin/forum/categories/<?= $e($cat['id']) ?>"><?= $e($name) ?></a>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);font-family:var(--font-mono);font-size:0.8125rem;color:var(--color-text-muted)"><?= $e($cat['slug']) ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:center;color:var(--color-text-muted)"><?= (int) $cat['sort_order'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:center">
                            <?php if ($cat['is_locked']): ?>
                                <span class="forum-badge forum-badge--locked" data-t="forum.category.locked"><?= @t('forum.category.locked') ?></span>
                            <?php else: ?>
                                <span class="forum-badge forum-badge--solved" data-t="forum.admin.categories.open"><?= @t('forum.admin.categories.open') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:center;color:var(--color-text-muted)"><?= count($cat['translations']) ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right">
                            <a href="/admin/forum/categories/<?= $e($cat['id']) ?>" class="forum-btn forum-btn--ghost forum-btn--sm" data-t="forum.admin.edit"><?= @t('forum.admin.edit') ?></a>
                            <form method="post" action="/admin/forum/categories/<?= $e($cat['id']) ?>" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="forum-btn forum-btn--danger forum-btn--sm" data-t="forum.admin.delete" onclick="return confirm('<?= @t('forum.admin.categories.confirm_delete') ?>')"><?= @t('forum.admin.delete') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
