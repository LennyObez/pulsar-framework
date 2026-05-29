<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 * @var string $title
 */
$e = static fn(string $val): string => htmlspecialchars($val);
$requestUri = $_SERVER['REQUEST_URI'] ?? null;
$currentPathRaw = is_string($requestUri) ? $requestUri : '/admin';
$currentPath = strtok($currentPathRaw, '?') ?: '/admin';

$schemaEnabled = ($templateData['schema_enabled'] ?? false) === true;

/** @var list<array{href: string, label: string, exact: bool}> $navItems */
$navItems = [
    ['href' => '/admin', 'label' => __('admin.nav.dashboard'), 'exact' => true],
    ['href' => '/admin/resources', 'label' => __('admin.nav.resources'), 'exact' => false],
    ...($schemaEnabled ? [['href' => '/admin/schema', 'label' => __('admin.nav.database'), 'exact' => false]] : []),
    ['href' => '/admin/activity', 'label' => __('admin.nav.activity'), 'exact' => false],
];
?>
<nav class="admin-nav" role="navigation" aria-label="<?= __('admin.nav.nav_label') ?>">
    <div class="admin-nav__brand">
        <a href="/admin" data-t="admin.nav.brand"><?= __('admin.nav.brand') ?></a>
    </div>
    <div class="admin-nav__search">
        <form action="/admin/search" method="get">
            <input type="search" name="q" placeholder="<?= __('admin.nav.search_placeholder') ?>" aria-label="<?= __('admin.nav.search_label') ?>" data-t-placeholder="admin.nav.search_placeholder" autocomplete="off">
        </form>
    </div>
    <ul class="admin-nav__links">
        <?php foreach ($navItems as $item): ?>
        <?php
            $isActive = $item['exact']
                ? $currentPath === $item['href']
                : str_starts_with($currentPath, $item['href']);
            ?>
        <li><a href="<?= $e($item['href']) ?>"<?= $isActive ? ' class="active"' : '' ?>><?= $e($item['label']) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php /** @var mixed $rawLocale */ $rawLocale = $locale ?? null; ?>
    <div data-language-selector data-locales="en,fr,nl,de,es,it,pt,pl,ro,cs,el,hu,sv,da,fi,sk,bg,hr,sl,lt,lv,et,ga,mt,lb" data-current="<?= htmlspecialchars(is_string($rawLocale) ? $rawLocale : 'en') ?>"></div>
</nav>
