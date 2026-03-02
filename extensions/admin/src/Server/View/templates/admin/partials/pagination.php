<?php

declare(strict_types=1);

/**
 * @var int $page
 * @var int $totalPages
 * @var string $baseUrl
 */
$e = static fn(string $val): string => htmlspecialchars($val);
?>
<?php if ($totalPages > 1): ?>
<nav class="admin-pagination" aria-label="<?= __('admin.pagination.page', ['page' => $page, 'pages' => $totalPages]) ?>">
    <ul>
        <?php if ($page > 1): ?>
        <li><a href="<?= $e($baseUrl . '?page=' . ($page - 1)) ?>" aria-label="<?= __('admin.pagination.previous') ?>" data-t="admin.pagination.previous">&laquo; <?= __('admin.pagination.previous') ?></a></li>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="<?= $i === $page ? 'active' : '' ?>">
            <?php if ($i === $page): ?>
            <span aria-current="page"><?= $i ?></span>
            <?php else: ?>
            <a href="<?= $e($baseUrl . '?page=' . $i) ?>"><?= $i ?></a>
            <?php endif; ?>
        </li>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <li><a href="<?= $e($baseUrl . '?page=' . ($page + 1)) ?>" aria-label="<?= __('admin.pagination.next') ?>" data-t="admin.pagination.next"><?= __('admin.pagination.next') ?> &raquo;</a></li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>
