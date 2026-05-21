<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var string $query */
$query = $templateData['query'] ?? '';
/** @var array<string, list<array<string, mixed>>> $results */
$results = $templateData['results'] ?? [];
/** @var int $totalMatches */
$totalMatches = $templateData['totalMatches'] ?? 0;
?>
<div class="admin-search">
    <form action="/admin/search" method="get" class="admin-search__form">
        <input type="search" name="q" value="<?= $e($query) ?>" placeholder="<?= __('admin.search.placeholder') ?>" data-t-placeholder="admin.search.placeholder" autofocus>
        <button type="submit" class="admin-btn admin-btn--primary" data-t="admin.search.submit"><?= __('admin.search.submit') ?></button>
    </form>

    <?php if ($query !== ''): ?>
    <p class="admin-search__summary" data-t="admin.search.results_found"><?= __('admin.search.results_found', ['count' => $totalMatches]) ?></p>

    <?php foreach ($results as $resourceName => $rows): ?>
    <section class="admin-search__section">
        <h3><?= $e($resourceName) ?></h3>
        <table class="admin-table admin-table--compact">
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php
                    /** @var mixed $value */
                    foreach ($row as $value): ?>
                    <td><?= $e(is_scalar($value) ? (string) $value : '') ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
