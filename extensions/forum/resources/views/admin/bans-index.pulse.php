<?php

declare(strict_types=1);

/**
 * Admin ban list.
 *
 * @var list<array{id: string, user_id: string, banned_by: string, reason: string, type: string, expires_at: ?string, created_at: string, is_active: bool}> $data
 * @var array{current_page: int, per_page: int, total: int, last_page: int} $pagination
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
$lastPage = $pagination['last_page'] ?? 1;
$currentPage = $pagination['current_page'] ?? 1;
?>

<div class="forum-admin-page-header">
    <h1 data-t="forum.admin.bans.title"><?= @t('forum.admin.bans.title') ?></h1>
    <button type="button" class="forum-btn forum-btn--primary" data-t="forum.admin.bans.new" onclick="document.getElementById('new-ban-form').toggleAttribute('hidden')"><?= @t('forum.admin.bans.new') ?></button>
</div>

<div class="forum-card" id="new-ban-form" hidden style="margin-bottom:var(--space-8)">
    <div class="forum-card-header"><h2 data-t="forum.admin.bans.create"><?= @t('forum.admin.bans.create') ?></h2></div>
    <div class="forum-card-body">
        <form method="post" action="/admin/forum/bans">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                <div class="forum-form-group">
                    <label for="ban-user" class="forum-label" data-t="forum.admin.bans.field.user_id"><?= @t('forum.admin.bans.field.user_id') ?></label>
                    <input type="text" id="ban-user" name="user_id" class="forum-input" required>
                </div>
                <div class="forum-form-group">
                    <label for="ban-type" class="forum-label" data-t="forum.admin.bans.field.type"><?= @t('forum.admin.bans.field.type') ?></label>
                    <select id="ban-type" name="type" class="forum-select">
                        <option value="temporary" data-t="forum.admin.bans.temporary"><?= @t('forum.admin.bans.temporary') ?></option>
                        <option value="permanent" data-t="forum.admin.bans.permanent"><?= @t('forum.admin.bans.permanent') ?></option>
                    </select>
                </div>
            </div>
            <div class="forum-form-group">
                <label for="ban-reason" class="forum-label" data-t="forum.admin.bans.field.reason"><?= @t('forum.admin.bans.field.reason') ?></label>
                <input type="text" id="ban-reason" name="reason" class="forum-input" required>
            </div>
            <div class="forum-form-group">
                <label for="ban-expires" class="forum-label" data-t="forum.admin.bans.field.expires"><?= @t('forum.admin.bans.field.expires') ?></label>
                <input type="datetime-local" id="ban-expires" name="expires_at" class="forum-input">
            </div>
            <button type="submit" class="forum-btn forum-btn--danger" data-t="forum.admin.bans.create"><?= @t('forum.admin.bans.create') ?></button>
        </form>
    </div>
</div>

<div class="forum-card">
    <?php if ($data === []): ?>
        <div class="forum-empty">
            <h3 data-t="forum.admin.bans.no_bans"><?= @t('forum.admin.bans.no_bans') ?></h3>
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse" role="table">
            <thead>
                <tr style="border-bottom:2px solid var(--color-border)">
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.user"><?= @t('forum.admin.bans.table.user') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.reason"><?= @t('forum.admin.bans.table.reason') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.type"><?= @t('forum.admin.bans.table.type') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.status"><?= @t('forum.admin.bans.table.status') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.expires"><?= @t('forum.admin.bans.table.expires') ?></th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)" data-t="forum.admin.bans.table.actions"><?= @t('forum.admin.bans.table.actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $ban): ?>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <td style="padding:var(--space-2) var(--space-6)">
                            <a href="/admin/forum/users/<?= $e($ban['user_id']) ?>"><?= $e($ban['user_id']) ?></a>
                            <div style="font-size:0.75rem;color:var(--color-text-disabled)">by <?= $e($ban['banned_by']) ?></div>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem"><?= $e($ban['reason']) ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:center">
                            <span class="forum-badge forum-badge--<?= $ban['type'] === 'permanent' ? 'locked' : 'pinned' ?>"><?= $e($ban['type']) ?></span>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:center">
                            <?php if ($ban['is_active']): ?>
                                <span class="forum-badge forum-badge--locked" data-t="forum.admin.bans.active"><?= @t('forum.admin.bans.active') ?></span>
                            <?php else: ?>
                                <span class="forum-badge forum-badge--closed" data-t="forum.admin.bans.expired"><?= @t('forum.admin.bans.expired') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.8125rem;color:var(--color-text-disabled)">
                            <?php if ($ban['expires_at'] !== null): ?>
                                <time datetime="<?= $e($ban['expires_at']) ?>"><?= $e($ban['expires_at']) ?></time>
                            <?php else: ?>
                                <?= @t('forum.admin.bans.never') ?>
                            <?php endif; ?>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right">
                            <?php if ($ban['is_active']): ?>
                                <form method="post" action="/admin/forum/bans/<?= $e($ban['id']) ?>/revoke" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <button type="submit" class="forum-btn forum-btn--ghost forum-btn--sm" data-t="forum.admin.bans.revoke" onclick="return confirm('<?= @t('forum.admin.bans.confirm_revoke') ?>')"><?= @t('forum.admin.bans.revoke') ?></button>
                                </form>
                            <?php endif; ?>
                            <a href="/admin/forum/bans/<?= $e($ban['id']) ?>" class="forum-btn forum-btn--ghost forum-btn--sm" data-t="forum.admin.bans.detail"><?= @t('forum.admin.bans.detail') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($lastPage > 1): ?>
    <nav class="forum-pagination" aria-label="<?= @t('forum.pagination.label') ?>">
        <?php if ($currentPage > 1): ?><a href="?page=<?= $currentPage - 1 ?>">&laquo;</a><?php endif; ?>
        <?php for ($i = 1; $i <= $lastPage; $i++): ?>
            <?php if ($i === $currentPage): ?><span class="active" aria-current="page"><?= $i ?></span><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($currentPage < $lastPage): ?><a href="?page=<?= $currentPage + 1 ?>">&raquo;</a><?php endif; ?>
    </nav>
<?php endif; ?>
