<?php

declare(strict_types=1);

/**
 * Base layout for forum back-office (admin) pages.
 *
 * @var string $page_title
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-extension="forum">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= $e($__csrf_token ?? '') ?>">
    <title><?= $e($page_title ?? @t('forum.admin.brand')) ?> &mdash; <?= @t('forum.admin.brand') ?></title>
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/forum/assets/forum.css">
    <style>
        .forum-admin-shell { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        .forum-admin-sidebar { background: var(--color-bg-secondary); border-right: 1px solid var(--color-border); padding: var(--space-6) 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        .forum-admin-sidebar-brand { display: flex; align-items: center; gap: var(--space-2); padding: 0 var(--space-6) var(--space-6); font-family: var(--font-heading); font-size: var(--text-lg); font-weight: var(--font-bold); color: var(--color-text); text-decoration: none; border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-4); }
        .forum-admin-sidebar-brand:hover { color: var(--ext-accent); text-decoration: none; }
        .forum-admin-nav { list-style: none; padding: 0 var(--space-2); }
        .forum-admin-nav li a { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-2) var(--space-4); border-radius: var(--radius-md); color: var(--color-text-muted); font-size: var(--text-sm); transition: all var(--transition-fast); text-decoration: none; }
        .forum-admin-nav li a:hover { background: var(--color-bg-tertiary); color: var(--color-text); text-decoration: none; }
        .forum-admin-nav li a.active { background: var(--ext-accent-dim); color: var(--ext-accent); text-decoration: none; }
        .forum-admin-nav-section { font-size: var(--text-xs); font-weight: var(--font-bold); text-transform: uppercase; letter-spacing: var(--tracking-wider); color: var(--color-text-muted); padding: var(--space-4) var(--space-4) var(--space-1); }
        .forum-admin-main { padding: var(--space-8) var(--space-10); overflow-y: auto; }
        .forum-admin-page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-8); }
        .forum-admin-page-header h1 { font-family: var(--font-heading); font-size: var(--text-2xl); font-weight: var(--font-bold); }
        @media (max-width: 768px) { .forum-admin-shell { grid-template-columns: 1fr; } .forum-admin-sidebar { display: none; } }
    </style>
</head>
<body>
    <a href="#admin-main" class="sr-only sr-only--focusable"><?= @t('forum.skip_to_content') ?></a>

    <div class="forum-admin-shell">
        <aside class="forum-admin-sidebar" role="navigation" aria-label="<?= @t('forum.admin.nav_label') ?>">
            <a href="/admin/forum" class="forum-admin-sidebar-brand">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?= @t('forum.admin.brand') ?>
            </a>

            <ul class="forum-admin-nav">
                <li class="forum-admin-nav-section"><?= @t('forum.admin.section.overview') ?></li>
                <li><a href="/admin/forum"><?= @t('forum.admin.nav.dashboard') ?></a></li>
                <li><a href="/admin/forum/leaderboard"><?= @t('forum.admin.nav.leaderboard') ?></a></li>

                <li class="forum-admin-nav-section"><?= @t('forum.admin.section.content') ?></li>
                <li><a href="/admin/forum/threads"><?= @t('forum.admin.nav.threads') ?></a></li>
                <li><a href="/admin/forum/categories"><?= @t('forum.admin.nav.categories') ?></a></li>
                <li><a href="/admin/forum/tags"><?= @t('forum.admin.nav.tags') ?></a></li>

                <li class="forum-admin-nav-section"><?= @t('forum.admin.section.community') ?></li>
                <li><a href="/admin/forum/users"><?= @t('forum.admin.nav.users') ?></a></li>
                <li><a href="/admin/forum/badges"><?= @t('forum.admin.nav.badges') ?></a></li>
                <li><a href="/admin/forum/bans"><?= @t('forum.admin.nav.bans') ?></a></li>

                <li class="forum-admin-nav-section"><?= @t('forum.admin.section.moderation') ?></li>
                <li><a href="/admin/forum/moderation"><?= @t('forum.admin.nav.queue') ?></a></li>
                <li><a href="/admin/forum/moderation-log"><?= @t('forum.admin.nav.action_log') ?></a></li>

                <li class="forum-admin-nav-section"><?= @t('forum.admin.section.config') ?></li>
                <li><a href="/admin/forum/settings"><?= @t('forum.admin.nav.settings') ?></a></li>
            </ul>

            <div style="padding:var(--space-6);border-top:1px solid var(--color-border);margin-top:auto">
                <a href="/forum" class="forum-btn forum-btn--ghost forum-btn--sm" style="width:100%;justify-content:center">&larr; <?= @t('forum.admin.back_to_forum') ?></a>
            </div>
        </aside>

        <main class="forum-admin-main" id="admin-main" role="main">
            <div style="display:flex;justify-content:flex-end;margin-bottom:var(--space-4)">
                <div data-language-selector data-locales="en,fr,nl,de,es,it,pt,pl,ro,cs,el,hu,sv,da,fi,sk,bg,hr,sl,lt,lv,et,ga,mt,lb" data-current="<?= $e($locale ?? 'en') ?>"></div>
            </div>
            <?= $__content ?? '' ?>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var path = window.location.pathname;
        document.querySelectorAll('.forum-admin-nav a').forEach(function(a) {
            if (a.getAttribute('href') === path) a.classList.add('active');
        });
        document.querySelectorAll('time[datetime]').forEach(function(el) {
            var d = new Date(el.getAttribute('datetime'));
            if (!isNaN(d)) el.textContent = d.toLocaleDateString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
        });
    });
    </script>
    <script src="/ui/js/language-selector.js" defer></script>
</body>
</html>
