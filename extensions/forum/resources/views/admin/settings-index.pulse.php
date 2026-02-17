<?php

declare(strict_types=1);

/**
 * Admin settings view (read-only; config file based).
 *
 * @var array{threads_per_page: int, posts_per_page: int, post_cooldown_seconds: int, require_thread_approval: bool, allow_guest_viewing: bool, max_title_length: int, max_body_length: int, max_tags_per_thread: int, edit_window_minutes: int, moderation: array{auto_hide_threshold: int, notify_threshold: int, dismissed_report_retention_days: int}, reputation: array{points_per_thread: int, points_per_post: int, points_per_upvote: int, points_per_downvote: int, points_per_solution: int, min_reputation_to_downvote: int}, badges: array{enabled: bool, helpful_upvote_threshold: int, popular_thread_view_threshold: int, solver_accepted_answer_threshold: int, bug_hunter_confirmed_threshold: int, multilingual_locale_threshold: int}} $settings
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bool = static fn(bool $v): string => $v ? '<span style="color:var(--forum-green)">Enabled</span>' : '<span style="color:var(--color-text-disabled)">Disabled</span>';
?>

<div class="forum-admin-page-header">
    <h1>Forum Settings</h1>
</div>

<div class="forum-alert forum-alert--info" style="margin-bottom:var(--space-8)">
    Settings are managed via the <code>config/forum.php</code> configuration file. This view is read-only.
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-8)">
    <div class="forum-card">
        <div class="forum-card-header"><h2>General</h2></div>
        <div class="forum-card-body">
            <table style="width:100%;border-collapse:collapse">
                <tbody>
                    <?php
                    $general = [
                        'Threads per page' => $settings['threads_per_page'],
                        'Posts per page' => $settings['posts_per_page'],
                        'Post cooldown (sec)' => $settings['post_cooldown_seconds'],
                        'Max title length' => $settings['max_title_length'],
                        'Max body length' => $settings['max_body_length'],
                        'Max tags per thread' => $settings['max_tags_per_thread'],
                        'Edit window (min)' => $settings['edit_window_minutes'],
                    ];
foreach ($general as $label => $value): ?>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem"><?= $e($label) ?></td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:600"><?= (int) $value ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="border-bottom:1px solid var(--color-border)">
                        <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem">Require thread approval</td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right"><?= $bool($settings['require_thread_approval']) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem">Allow guest viewing</td>
                        <td style="padding:var(--space-2) var(--space-6);text-align:right"><?= $bool($settings['allow_guest_viewing']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-8)">
        <div class="forum-card">
            <div class="forum-card-header"><h2>Moderation</h2></div>
            <div class="forum-card-body">
                <table style="width:100%;border-collapse:collapse">
                    <tbody>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem">Auto-hide threshold</td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:600"><?= (int) $settings['moderation']['auto_hide_threshold'] ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem">Notify threshold</td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:600"><?= (int) $settings['moderation']['notify_threshold'] ?></td>
                        </tr>
                        <tr>
                            <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem">Report retention (days)</td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:600"><?= (int) $settings['moderation']['dismissed_report_retention_days'] ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="forum-card">
            <div class="forum-card-header"><h2>Reputation</h2></div>
            <div class="forum-card-body">
                <table style="width:100%;border-collapse:collapse">
                    <tbody>
                        <?php
    $rep = [
        'Per thread' => $settings['reputation']['points_per_thread'],
        'Per post' => $settings['reputation']['points_per_post'],
        'Per upvote' => $settings['reputation']['points_per_upvote'],
        'Per downvote' => $settings['reputation']['points_per_downvote'],
        'Per solution' => $settings['reputation']['points_per_solution'],
        'Min rep to downvote' => $settings['reputation']['min_reputation_to_downvote'],
    ];
foreach ($rep as $label => $value): ?>
                            <tr style="border-bottom:1px solid var(--color-border)">
                                <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem"><?= $e($label) ?></td>
                                <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:600"><?= (int) $value ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="forum-card">
            <div class="forum-card-header"><h2>Badges</h2></div>
            <div class="forum-card-body">
                <table style="width:100%;border-collapse:collapse">
                    <tbody>
                        <tr style="border-bottom:1px solid var(--color-border)">
                            <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem">Badges enabled</td>
                            <td style="padding:var(--space-2) var(--space-6);text-align:right"><?= $bool($settings['badges']['enabled']) ?></td>
                        </tr>
                        <?php
$badgeSettings = [
    'Helpful upvote threshold' => $settings['badges']['helpful_upvote_threshold'],
    'Popular thread views' => $settings['badges']['popular_thread_view_threshold'],
    'Solver answers' => $settings['badges']['solver_accepted_answer_threshold'],
    'Bug hunter confirmed' => $settings['badges']['bug_hunter_confirmed_threshold'],
    'Multilingual locales' => $settings['badges']['multilingual_locale_threshold'],
];
foreach ($badgeSettings as $label => $value): ?>
                            <tr style="border-bottom:1px solid var(--color-border)">
                                <td style="padding:var(--space-2) var(--space-6);color:var(--color-text-muted);font-size:0.875rem"><?= $e($label) ?></td>
                                <td style="padding:var(--space-2) var(--space-6);text-align:right;font-weight:600"><?= (int) $value ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
