<?php

declare(strict_types=1);

/**
 * Admin badge overview.
 *
 * @var list<array{value: string, name: string}> $badges
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.badges.title"><?= @t('forum.admin.badges.title') ?></h1>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-8)">
    <div class="forum-card">
        <div class="forum-card-header"><h2 data-t="forum.admin.badges.available"><?= @t('forum.admin.badges.available') ?></h2></div>
        <?php if ($badges === []): ?>
            <div class="forum-card-body"><p style="color:var(--color-text-muted)" data-t="forum.admin.badges.no_badges"><?= @t('forum.admin.badges.no_badges') ?></p></div>
        <?php else: ?>
            <div class="forum-card-body" style="display:flex;flex-wrap:wrap;gap:var(--space-4)">
                <?php foreach ($badges as $badge): ?>
                    <div style="background:var(--forum-surface-raised);border-radius:var(--radius-md);padding:var(--space-4) var(--space-6);text-align:center;min-width:120px">
                        <div style="font-size:1.5rem;margin-bottom:var(--space-1)">&#127942;</div>
                        <div style="font-weight:600;font-size:0.875rem"><?= $e($badge['name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--color-text-disabled);font-family:var(--font-mono)"><?= $e($badge['value']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="forum-card">
        <div class="forum-card-header"><h2 data-t="forum.admin.badges.award"><?= @t('forum.admin.badges.award') ?></h2></div>
        <div class="forum-card-body">
            <form method="post" action="/admin/forum/badges/award">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="forum-form-group">
                    <label for="award-user" class="forum-label" data-t="forum.admin.badges.field.user_id"><?= @t('forum.admin.badges.field.user_id') ?></label>
                    <input type="text" id="award-user" name="user_id" class="forum-input" required>
                </div>
                <div class="forum-form-group">
                    <label for="award-badge" class="forum-label" data-t="forum.admin.badges.field.badge"><?= @t('forum.admin.badges.field.badge') ?></label>
                    <select id="award-badge" name="badge" class="forum-select" required>
                        <option value="" data-t="forum.admin.badges.select"><?= @t('forum.admin.badges.select') ?></option>
                        <?php foreach ($badges as $badge): ?>
                            <option value="<?= $e($badge['value']) ?>"><?= $e($badge['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="forum-btn forum-btn--primary" style="width:100%" data-t="forum.admin.badges.award_submit"><?= @t('forum.admin.badges.award_submit') ?></button>
            </form>

            <hr style="border:none;border-top:1px solid var(--color-border);margin:var(--space-8) 0">

            <h3 style="font-size:0.875rem;font-weight:600;margin-bottom:var(--space-4)" data-t="forum.admin.badges.revoke"><?= @t('forum.admin.badges.revoke') ?></h3>
            <form method="post" action="/admin/forum/badges/revoke">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="forum-form-group">
                    <label for="revoke-user" class="forum-label" data-t="forum.admin.badges.field.user_id"><?= @t('forum.admin.badges.field.user_id') ?></label>
                    <input type="text" id="revoke-user" name="user_id" class="forum-input" required>
                </div>
                <div class="forum-form-group">
                    <label for="revoke-badge" class="forum-label" data-t="forum.admin.badges.field.badge"><?= @t('forum.admin.badges.field.badge') ?></label>
                    <select id="revoke-badge" name="badge" class="forum-select" required>
                        <option value="" data-t="forum.admin.badges.select"><?= @t('forum.admin.badges.select') ?></option>
                        <?php foreach ($badges as $badge): ?>
                            <option value="<?= $e($badge['value']) ?>"><?= $e($badge['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="forum-btn forum-btn--danger" style="width:100%" data-t="forum.admin.badges.revoke_submit"><?= @t('forum.admin.badges.revoke_submit') ?></button>
            </form>
        </div>
    </div>
</div>
