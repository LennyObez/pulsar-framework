<?php

declare(strict_types=1);

/**
 * Admin user badges view.
 *
 * @var string $user_id
 * @var list<array{id: string, badge: string, awarded_at: string}> $badges
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.badges.user_title"><?= @t('forum.admin.badges.user_title', ['user' => $e($user_id)]) ?></h1>
    <a href="/admin/forum/badges" class="forum-btn forum-btn--ghost forum-btn--sm" data-t="forum.admin.badges.back">&larr; <?= @t('forum.admin.badges.back') ?></a>
</div>

<div class="forum-card">
    <?php if ($badges === []): ?>
        <div class="forum-empty">
            <h3 data-t="forum.admin.badges.no_user_badges"><?= @t('forum.admin.badges.no_user_badges') ?></h3>
            <p data-t="forum.admin.badges.no_user_badges_hint"><?= @t('forum.admin.badges.no_user_badges_hint') ?></p>
        </div>
    <?php else: ?>
        <div class="forum-card-body" style="display:flex;flex-wrap:wrap;gap:var(--space-6)">
            <?php foreach ($badges as $b): ?>
                <div style="background:var(--forum-surface-raised);border-radius:var(--radius-md);padding:var(--space-6);text-align:center;min-width:140px">
                    <div style="font-size:2rem;margin-bottom:var(--space-2)">&#127942;</div>
                    <div style="font-weight:600;font-size:0.875rem;margin-bottom:var(--space-1)"><?= $e($b['badge']) ?></div>
                    <time datetime="<?= $e($b['awarded_at']) ?>" style="font-size:0.75rem;color:var(--color-text-disabled)"><?= $e($b['awarded_at']) ?></time>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
