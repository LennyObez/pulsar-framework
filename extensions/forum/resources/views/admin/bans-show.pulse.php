<?php

declare(strict_types=1);

/**
 * Admin ban detail with history.
 *
 * @var array{id: string, user_id: string, banned_by: string, reason: string, type: string, expires_at: ?string, created_at: string, revoked_at: ?string, is_active: bool} $ban
 * @var list<array{id: string, type: string, reason: string, created_at: string, revoked_at: ?string, is_active: bool}> $user_ban_history
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.bans.ban_detail"><?= @t('forum.admin.bans.ban_detail') ?></h1>
    <a href="/admin/forum/bans" class="forum-btn forum-btn--ghost forum-btn--sm" data-t="forum.admin.bans.title">&larr; <?= @t('forum.admin.bans.title') ?></a>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--space-8)">
    <div>
        <div class="forum-card" style="margin-bottom:var(--space-8)">
            <div class="forum-card-header"><h2 data-t="forum.admin.bans.ban_info"><?= @t('forum.admin.bans.ban_info') ?></h2></div>
            <div class="forum-card-body">
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:var(--space-1) var(--space-6);font-size:0.875rem">
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.table.user"><?= @t('forum.admin.bans.table.user') ?></dt>
                    <dd><a href="/admin/forum/users/<?= $e($ban['user_id']) ?>"><?= $e($ban['user_id']) ?></a></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.field.banned_by"><?= @t('forum.admin.bans.field.banned_by') ?></dt>
                    <dd><?= $e($ban['banned_by']) ?></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.table.reason"><?= @t('forum.admin.bans.table.reason') ?></dt>
                    <dd><?= $e($ban['reason']) ?></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.table.type"><?= @t('forum.admin.bans.table.type') ?></dt>
                    <dd><span class="forum-badge forum-badge--<?= $ban['type'] === 'permanent' ? 'locked' : 'pinned' ?>"><?= $e($ban['type']) ?></span></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.table.status"><?= @t('forum.admin.bans.table.status') ?></dt>
                    <dd><?= $ban['is_active'] ? '<span class="forum-badge forum-badge--locked">' . @t('forum.admin.bans.active') . '</span>' : '<span class="forum-badge forum-badge--closed">' . @t('forum.admin.bans.inactive') . '</span>' ?></dd>
                    <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.field.created"><?= @t('forum.admin.bans.field.created') ?></dt>
                    <dd><time datetime="<?= $e($ban['created_at']) ?>"><?= $e($ban['created_at']) ?></time></dd>
                    <?php if ($ban['expires_at'] !== null): ?>
                        <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.table.expires"><?= @t('forum.admin.bans.table.expires') ?></dt>
                        <dd><time datetime="<?= $e($ban['expires_at']) ?>"><?= $e($ban['expires_at']) ?></time></dd>
                    <?php endif; ?>
                    <?php if ($ban['revoked_at'] !== null): ?>
                        <dt style="color:var(--color-text-disabled)" data-t="forum.admin.bans.field.revoked"><?= @t('forum.admin.bans.field.revoked') ?></dt>
                        <dd><time datetime="<?= $e($ban['revoked_at']) ?>"><?= $e($ban['revoked_at']) ?></time></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="forum-card">
            <div class="forum-card-header"><h2 data-t="forum.admin.bans.user_history"><?= @t('forum.admin.bans.user_history') ?></h2></div>
            <?php if ($user_ban_history === []): ?>
                <div class="forum-card-body"><p style="color:var(--color-text-muted)" data-t="forum.admin.bans.no_history"><?= @t('forum.admin.bans.no_history') ?></p></div>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.type"><?= @t('forum.admin.bans.table.type') ?></th>
                            <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.reason"><?= @t('forum.admin.bans.table.reason') ?></th>
                            <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.active"><?= @t('forum.admin.bans.active') ?></th>
                            <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.field.created"><?= @t('forum.admin.bans.field.created') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user_ban_history as $h): ?>
                            <tr style="border-bottom:1px solid var(--color-border)<?= $h['id'] === $ban['id'] ? ';background:var(--forum-surface-hover)' : '' ?>">
                                <td style="padding:var(--space-2) var(--space-6)"><?= $e($h['type']) ?></td>
                                <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted)"><?= $e($h['reason']) ?></td>
                                <td style="padding:var(--space-2) var(--space-6);text-align:center"><?= $h['is_active'] ? @t('forum.admin.yes') : @t('forum.admin.no') ?></td>
                                <td style="padding:var(--space-2) var(--space-6);text-align:right"><time datetime="<?= $e($h['created_at']) ?>"><?= $e($h['created_at']) ?></time></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="forum-card" style="align-self:start">
        <div class="forum-card-header"><h3 data-t="forum.admin.actions"><?= @t('forum.admin.actions') ?></h3></div>
        <div class="forum-card-body">
            <?php if ($ban['is_active']): ?>
                <form method="post" action="/admin/forum/bans/<?= $e($ban['id']) ?>/revoke">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button type="submit" class="forum-btn forum-btn--secondary" style="width:100%" data-t="forum.admin.bans.revoke_ban" onclick="return confirm('<?= @t('forum.admin.bans.confirm_revoke') ?>')"><?= @t('forum.admin.bans.revoke_ban') ?></button>
                </form>
            <?php else: ?>
                <p style="color:var(--color-text-muted);font-size:0.875rem" data-t="forum.admin.bans.not_active"><?= @t('forum.admin.bans.not_active') ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
