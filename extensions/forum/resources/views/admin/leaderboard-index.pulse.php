<?php

declare(strict_types=1);

/**
 * Admin leaderboard.
 *
 * @var list<array{rank: int, user_id: string, reputation_score: int, reputation_level: string, post_count: int, thread_count: int, member_since: string}> $data
 * @var string $period
 * @var int    $limit
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<div class="forum-admin-page-header">
    <h1>Leaderboard</h1>
</div>

<div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-8)">
    <?php foreach (['all' => 'All Time', 'month' => 'This Month', 'week' => 'This Week'] as $key => $label): ?>
        <a href="?period=<?= $key ?>" class="forum-btn <?= $period === $key ? 'forum-btn--primary' : 'forum-btn--ghost' ?> forum-btn--sm">
            <?= $label ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="forum-card">
    <?php if ($data === []): ?>
        <div class="forum-empty">
            <h3>No data</h3>
            <p>No users found for this period.</p>
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse" role="table">
            <thead>
                <tr style="border-bottom:2px solid var(--color-border)">
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled);width:60px">Rank</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:left;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">User</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:center;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Level</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Reputation</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Posts</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Threads</th>
                    <th style="padding:var(--space-2) var(--space-6);text-align:right;font-size:0.75rem;text-transform:uppercase;color:var(--color-text-disabled)">Member Since</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $entry): ?>
                    <tr style="border-bottom:1px solid var(--color-border)<?= $entry['rank'] <= 3 ? ';background:var(--forum-surface-hover)' : '' ?>">
                        <td style="padding:var(--space-2) var(--space-6);text-align:center;font-weight:700;font-size:1.125rem">
                            <?php if ($entry['rank'] === 1): ?>&#129351;<?php elseif ($entry['rank'] === 2): ?>&#129352;<?php elseif ($entry['rank'] === 3): ?>&#129353;<?php else: ?><?= $entry['rank'] ?><?php endif; ?>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6)">
                            <a href="/admin/forum/users/<?= $e($entry['user_id']) ?>" style="font-weight:600"><?= $e($entry['user_id']) ?></a>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:center">
                            <span class="forum-reputation-badge"><?= $e(ucfirst($entry['reputation_level'])) ?></span>
                        </td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:700;color:var(--ext-accent)"><?= (int) $entry['reputation_score'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;color:var(--color-text-muted)"><?= (int) $entry['post_count'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right;color:var(--color-text-muted)"><?= (int) $entry['thread_count'] ?></td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right">
                            <time datetime="<?= $e($entry['member_since']) ?>" style="font-size:0.8125rem;color:var(--color-text-disabled)"><?= $e($entry['member_since']) ?></time>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
