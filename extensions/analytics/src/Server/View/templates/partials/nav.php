<?php declare(strict_types=1); ?>
<nav class="analytics-nav">
    <div class="analytics-nav__brand">
        <span class="analytics-nav__logo">P</span>
        <span class="analytics-nav__title" data-t="analytics.nav.title"><?= __('analytics.nav.title') ?></span>
    </div>
    <ul class="analytics-nav__links">
        <li><a href="/analytics" class="analytics-nav__link" data-t="analytics.nav.dashboard"><?= __('analytics.nav.dashboard') ?></a></li>
        <li><a href="/analytics/sites" class="analytics-nav__link" data-t="analytics.nav.sites"><?= __('analytics.nav.sites') ?></a></li>
        <li><a href="/analytics/goals" class="analytics-nav__link" data-t="analytics.nav.goals"><?= __('analytics.nav.goals') ?></a></li>
        <li><a href="/analytics/settings" class="analytics-nav__link" data-t="analytics.nav.settings"><?= __('analytics.nav.settings') ?></a></li>
    </ul>
    <div data-language-selector data-locales="en,fr,nl,de,es,it,pt,pl,ro,cs,el,hu,sv,da,fi,sk,bg,hr,sl,lt,lv,et,ga,mt,lb" data-current="<?= htmlspecialchars(is_string($locale ?? null) ? $locale : 'en') ?>"></div>
</nav>
