<?php

declare(strict_types=1);

/**
 * Base layout for front-office forum pages.
 *
 * @var string $page_title
 * @var bool   $__is_authenticated
 * @var string $__csrf_token
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isAuth = $__is_authenticated ?? false;
?>
<!DOCTYPE html>
<html lang="en" data-extension="forum">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="<?= $e($meta_robots ?? 'index, follow') ?>">
    <meta name="csrf-token" content="<?= $e($__csrf_token ?? '') ?>">
    <title><?= $e($page_title ?? 'Forum') ?> &mdash; Pulsar Forum</title>
    <?php if (isset($meta_description) && $meta_description !== ''): ?>
        <meta name="description" content="<?= $e($meta_description) ?>">
    <?php endif; ?>
    <?php if (isset($canonical_url) && $canonical_url !== ''): ?>
        <link rel="canonical" href="<?= $e($canonical_url) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $e($page_title ?? 'Forum') ?>">
    <?php if (isset($meta_description) && $meta_description !== ''): ?>
        <meta property="og:description" content="<?= $e($meta_description) ?>">
    <?php endif; ?>
    <?php if (isset($canonical_url) && $canonical_url !== ''): ?>
        <meta property="og:url" content="<?= $e($canonical_url) ?>">
    <?php endif; ?>
    <meta property="og:site_name" content="Pulsar Forum">
    <link rel="alternate" type="application/rss+xml" title="Pulsar Forum RSS" href="/forum/feed/rss">
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/forum/assets/forum.css">
</head>
<body>
    <a href="#main-content" class="sr-only sr-only--focusable"><?= @t('forum.skip_to_content') ?></a>

    <header class="forum-header" role="banner">
        <a href="/forum" class="forum-logo" aria-label="<?= @t('forum.nav.forum_home') ?>">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?= @t('forum.breadcrumb.forum') ?>
        </a>

        <nav class="forum-nav" id="forum-nav" aria-label="<?= @t('forum.nav.main') ?>">
            <a href="/forum"><?= @t('forum.nav.home') ?></a>
            <a href="/forum/tags"><?= @t('forum.nav.tags') ?></a>
            <a href="/forum/search"><?= @t('forum.nav.search') ?></a>
            <?php if ($isAuth): ?>
                <div class="forum-nav-auth">
                    <a href="/forum/new-thread" class="forum-btn forum-btn--primary forum-btn--sm"><?= @t('forum.nav.new_thread') ?></a>
                    <form method="post" action="/forum/logout" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= $e($__csrf_token ?? '') ?>">
                        <button type="submit" class="forum-btn forum-btn--ghost forum-btn--sm"><?= @t('forum.nav.sign_out') ?></button>
                    </form>
                </div>
            <?php else: ?>
                <div class="forum-nav-auth">
                    <a href="/forum/login" class="forum-btn forum-btn--ghost forum-btn--sm"><?= @t('forum.nav.sign_in') ?></a>
                    <a href="/forum/register" class="forum-btn forum-btn--primary forum-btn--sm"><?= @t('forum.nav.sign_up') ?></a>
                </div>
            <?php endif; ?>
        </nav>

        <div data-language-selector data-locales="en,fr,nl,de,es,it,pt,pl,ro,cs,el,hu,sv,da,fi,sk,bg,hr,sl,lt,lv,et,ga,mt,lb" data-current="<?= $e($locale ?? 'en') ?>"></div>

        <button class="forum-hamburger" type="button" aria-label="<?= @t('forum.nav.toggle') ?>" aria-expanded="false" aria-controls="forum-nav" onclick="document.getElementById('forum-nav').classList.toggle('open');this.setAttribute('aria-expanded',this.getAttribute('aria-expanded')==='false'?'true':'false')">
            &#9776;
        </button>
    </header>

    <div class="forum-container">
        <main id="main-content" role="main">
            <?= $__content ?? '' ?>
        </main>
    </div>

    <footer class="forum-footer" role="contentinfo">
        <?= @t('forum.footer.powered_by') ?>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('time[datetime]').forEach(function(el) {
            var d = new Date(el.getAttribute('datetime'));
            if (!isNaN(d)) el.textContent = d.toLocaleDateString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
        });
    });
    </script>
    <script src="/ui/js/language-selector.js" defer></script>
</body>
</html>
