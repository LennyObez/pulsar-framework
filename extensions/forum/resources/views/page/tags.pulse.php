<?php

declare(strict_types=1);

/**
 * Tags page: browse all tags.
 *
 * @var list<array{id: string, slug: string, name: string, description: ?string, usage_count: int}> $tags
 * @var string $page_title
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<nav class="forum-breadcrumbs" aria-label="<?= @t('forum.breadcrumb.label') ?>">
    <a href="/forum"><?= @t('forum.breadcrumb.forum') ?></a>
    <span class="separator" aria-hidden="true">/</span>
    <span aria-current="page"><?= @t('forum.tags.title') ?></span>
</nav>

<div class="forum-page-title">
    <h1><?= @t('forum.tags.title') ?></h1>
</div>

<?php if ($tags === []): ?>
    <div class="forum-empty">
        <div class="forum-empty-icon" aria-hidden="true">&#127991;</div>
        <h3><?= @t('forum.tags.no_tags') ?></h3>
        <p><?= @t('forum.tags.no_tags_hint') ?></p>
    </div>
<?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:var(--space-4)">
        <?php foreach ($tags as $tag): ?>
            <a href="/forum/tags/<?= $e($tag['slug']) ?>" class="forum-card" style="display:block;padding:var(--space-6);flex:0 0 auto;min-width:200px;text-decoration:none;transition:background var(--transition-fast)" role="group" aria-label="<?= $e($tag['name']) ?>">
                <div style="font-weight:600;color:var(--ext-accent);margin-bottom:var(--space-1)">
                    <?= $e($tag['name']) ?>
                </div>
                <?php if ($tag['description'] !== null && $tag['description'] !== ''): ?>
                    <p style="font-size:0.8125rem;color:var(--color-text-muted);margin-bottom:var(--space-2)">
                        <?= $e($tag['description']) ?>
                    </p>
                <?php endif; ?>
                <span style="font-size:0.75rem;color:var(--color-text-disabled)">
                    <?= @t('forum.tags.threads_count', ['count' => (int) $tag['usage_count']]) ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
