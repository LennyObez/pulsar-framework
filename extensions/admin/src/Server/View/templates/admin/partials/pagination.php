<?php

declare(strict_types=1);

/**
 * @var int $page
 * @var int $totalPages
 * @var string $baseUrl
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<?php if ($totalPages > 1): ?>
<nav class="admin-pagination" aria-label="Pagination">
    <ul>
        <?php if ($page > 1): ?>
        <li><a href="<?= $e($baseUrl . '?page=' . ($page - 1)) ?>" aria-label="Previous page">&laquo; Prev</a></li>
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
        <li><a href="<?= $e($baseUrl . '?page=' . ($page + 1)) ?>" aria-label="Next page">Next &raquo;</a></li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>
