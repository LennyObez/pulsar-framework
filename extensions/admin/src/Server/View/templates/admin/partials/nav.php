<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 * @var string $title
 */
$e = static fn(string $val): string => htmlspecialchars($val);
$currentPath = $_SERVER['REQUEST_URI'] ?? '/admin';
$currentPath = strtok($currentPath, '?') ?: '/admin';

$schemaEnabled = ($templateData['schema_enabled'] ?? false) === true;

$navItems = [
    ['href' => '/admin', 'label' => 'Dashboard', 'exact' => true],
    ['href' => '/admin/resources', 'label' => 'Resources', 'exact' => false],
    ...($schemaEnabled ? [['href' => '/admin/schema', 'label' => 'Database', 'exact' => false]] : []),
    ['href' => '/admin/activity', 'label' => 'Activity', 'exact' => false],
];
?>
<nav class="admin-nav" role="navigation" aria-label="Admin navigation">
    <div class="admin-nav__brand">
        <a href="/admin"><?= $e('Pulsar Admin') ?></a>
    </div>
    <div class="admin-nav__search">
        <form action="/admin/search" method="get">
            <input type="search" name="q" placeholder="Search resources..." aria-label="Global search" autocomplete="off">
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
</nav>
