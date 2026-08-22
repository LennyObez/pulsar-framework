<?php

declare(strict_types=1);

/**
 * Admin user profile view.
 *
 * @var array{id: string, user_id: string, reputation_score: int, reputation_level: string, post_count: int, thread_count: int, is_banned: bool, ban_reason: ?string, banned_at: ?string, ban_expires_at: ?string, created_at: string, updated_at: string} $profile
 * @var list<array{badge: string, awarded_at: string}> $badges
 * @var string $reputation_level
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = $__csrf_token ?? '';
?>

<div class="forum-admin-page-header">
    <h1>User: <?= $e($profile['user_id']) ?></h1>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--space-8)">
    <div>
        <div class="forum-card" style="margin-bottom:var(--space-8)">
            <div class="forum-card-header"><h2>Profile</h2></div>
            <div class="forum-card-body">
                <?php if ($profile['is_banned']): ?>
                    <div class="forum-alert forum-alert--error" style="margin-bottom:var(--space-6)">
                        This user is banned<?= $profile['ban_reason'] !== null ? ': ' . $e($profile['ban_reason']) : '' ?>
                        <?php if ($profile['ban_expires_at'] !== null): ?>
                            (expires <time datetime="<?= $e($profile['ban_expires_at']) ?>"><?= $e($profile['ban_expires_at']) ?></time>)
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="forum-stat-grid" style="margin-bottom:var(--space-6)">
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= (int) $profile['reputation_score'] ?></div>
                        <div class="forum-stat-label">Reputation</div>
                    </div>
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= (int) $profile['thread_count'] ?></div>
                        <div class="forum-stat-label">Threads</div>
                    </div>
                    <div class="forum-stat-card">
                        <div class="forum-stat-value"><?= (int) $profile['post_count'] ?></div>
                        <div class="forum-stat-label">Posts</div>
                    </div>
                </div>

                <dl style="display:grid;grid-template-columns:auto 1fr;gap:var(--space-1) var(--space-6);font-size:0.875rem">
                    <dt style="color:var(--color-text-disabled)">Level</dt>
                    <dd><span class="forum-reputation-badge"><?= $e(ucfirst($reputation_level)) ?></span></dd>
                    <dt style="color:var(--color-text-disabled)">Member Since</dt>
                    <dd><time datetime="<?= $e($profile['created_at']) ?>"><?= $e($profile['created_at']) ?></time></dd>
                </dl>
            </div>
        </div>

        <?php if ($badges !== []): ?>
            <div class="forum-card">
                <div class="forum-card-header"><h2>Badges</h2></div>
                <div class="forum-card-body" style="display:flex;flex-wrap:wrap;gap:var(--space-2)">
                    <?php foreach ($badges as $b): ?>
                        <span class="forum-badge forum-badge--moderator" title="Awarded <?= $e($b['awarded_at']) ?>">
                            <?= $e($b['badge']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-6)">
        <div class="forum-card">
            <div class="forum-card-header"><h3>Moderation</h3></div>
            <div class="forum-card-body" style="display:flex;flex-direction:column;gap:var(--space-2)">
                <?php if ($profile['is_banned']): ?>
                    <form method="post" action="/admin/forum/users/<?= $e($profile['user_id']) ?>/unban">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="forum-btn forum-btn--secondary" style="width:100%">Unban User</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/admin/forum/users/<?= $e($profile['user_id']) ?>/ban">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <div class="forum-form-group">
                            <label for="ban-reason" class="forum-label">Ban Reason</label>
                            <input type="text" id="ban-reason" name="reason" class="forum-input" required>
                        </div>
                        <div class="forum-form-group">
                            <label for="ban-expires" class="forum-label">Expires At (optional)</label>
                            <input type="datetime-local" id="ban-expires" name="expires_at" class="forum-input">
                        </div>
                        <button type="submit" class="forum-btn forum-btn--danger" style="width:100%">Ban User</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="forum-card">
            <div class="forum-card-header"><h3>Reputation</h3></div>
            <div class="forum-card-body">
                <form method="post" action="/admin/forum/users/<?= $e($profile['user_id']) ?>/promote">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <div class="forum-form-group">
                        <label for="rep-points" class="forum-label">Points</label>
                        <input type="number" id="rep-points" name="points" class="forum-input" required>
                    </div>
                    <div class="forum-form-group">
                        <label for="rep-reason" class="forum-label">Reason</label>
                        <input type="text" id="rep-reason" name="reason" class="forum-input" value="admin_promotion">
                    </div>
                    <button type="submit" class="forum-btn forum-btn--primary" style="width:100%">Add Reputation</button>
                </form>
            </div>
        </div>
    </div>
</div>
