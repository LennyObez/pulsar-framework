<?php

declare(strict_types=1);

/**
 * Admin category detail with translations and children.
 *
 * @var array{id: string, parent_id: ?string, slug: string, sort_order: int, is_locked: bool, created_at: string, updated_at: string, translations: list<array{id: string, locale: string, name: string, description: string}>} $category
 * @var list<array{id: string, parent_id: ?string, slug: string, sort_order: int, is_locked: bool, created_at: string, updated_at: string, translations: list<array{id: string, locale: string, name: string, description: string}>}> $children
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
$name = $category['slug'];
foreach ($category['translations'] as $t) {
    if ($t['locale'] === 'en') {
        $name = $t['name'];
        break;
    }
}
?>

<div class="forum-admin-page-header">
    <h1><?= $e($name) ?></h1>
    <a href="/admin/forum/categories" class="forum-btn forum-btn--ghost forum-btn--sm">&larr; Back</a>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--space-8)">
    <div>
        <div class="forum-card" style="margin-bottom:var(--space-8)">
            <div class="forum-card-header"><h2>Details</h2></div>
            <div class="forum-card-body">
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:var(--space-1) var(--space-6);font-size:0.875rem">
                    <dt style="color:var(--color-text-disabled)">Slug</dt>
                    <dd style="font-family:var(--font-mono)"><?= $e($category['slug']) ?></dd>
                    <dt style="color:var(--color-text-disabled)">Sort Order</dt>
                    <dd><?= (int) $category['sort_order'] ?></dd>
                    <dt style="color:var(--color-text-disabled)">Status</dt>
                    <dd><?= $category['is_locked'] ? '<span class="forum-badge forum-badge--locked">Locked</span>' : '<span class="forum-badge forum-badge--solved">Open</span>' ?></dd>
                </dl>
            </div>
        </div>

        <div class="forum-card" style="margin-bottom:var(--space-8)">
            <div class="forum-card-header"><h2>Translations</h2></div>
            <?php if ($category['translations'] === []): ?>
                <div class="forum-card-body"><p style="color:var(--color-text-muted)">No translations.</p></div>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Locale</th>
                            <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Name</th>
                            <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($category['translations'] as $t): ?>
                            <tr style="border-bottom:1px solid var(--color-border)">
                                <td style="padding:var(--space-2) var(--space-6);font-weight:600"><?= $e($t['locale']) ?></td>
                                <td style="padding:var(--space-2) var(--space-6)"><?= $e($t['name']) ?></td>
                                <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted)"><?= $e($t['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if ($children !== []): ?>
            <div class="forum-card">
                <div class="forum-card-header"><h2>Subcategories</h2></div>
                <ul class="forum-category-list" role="list">
                    <?php foreach ($children as $child): ?>
                        <?php
                        $childName = $child['slug'];
                        foreach ($child['translations'] as $ct) {
                            if ($ct['locale'] === 'en') {
                                $childName = $ct['name'];
                                break;
                            }
                        }
                        ?>
                        <li class="forum-category-item">
                            <div class="forum-category-info">
                                <a href="/admin/forum/categories/<?= $e($child['id']) ?>" class="forum-category-name"><?= $e($childName) ?></a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="forum-card" style="align-self:start">
        <div class="forum-card-header"><h3>Actions</h3></div>
        <div class="forum-card-body" style="display:flex;flex-direction:column;gap:var(--space-2)">
            <form method="post" action="/admin/forum/categories/<?= $e($category['id']) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="forum-btn forum-btn--danger" style="width:100%" onclick="return confirm('Delete this category?')">Delete Category</button>
            </form>
        </div>
    </div>
</div>
