<?php
/**
 * @var int|null $total
 * @var int|null $limit
 * @var int|null $offset
 * @var string|null $baseUrl
 */
$paginationTotal = $total ?? 0;
$paginationLimit = max($limit ?? 50, 1);
$paginationOffset = $offset ?? 0;
$paginationBaseUrl = $baseUrl ?? '';
$totalPages = (int) ceil($paginationTotal / $paginationLimit);
$currentPage = (int) floor($paginationOffset / $paginationLimit) + 1;
if ($totalPages > 1):
    ?>
<div class="pagination">
    <?php if ($currentPage > 1): ?>
        <a href="<?= htmlspecialchars($paginationBaseUrl . '?offset=' . (($currentPage - 2) * $paginationLimit) . '&limit=' . $paginationLimit, ENT_QUOTES, 'UTF-8') ?>" class="page-link" data-t="studio.pagination.previous">&laquo; <?= __('studio.pagination.previous') ?></a>
    <?php endif; ?>
    <span class="page-info"><?= __('studio.pagination.showing', ['from' => $currentPage, 'to' => $totalPages, 'total' => $totalPages]) ?></span>
    <?php if ($currentPage < $totalPages): ?>
        <a href="<?= htmlspecialchars($paginationBaseUrl . '?offset=' . ($currentPage * $paginationLimit) . '&limit=' . $paginationLimit, ENT_QUOTES, 'UTF-8') ?>" class="page-link" data-t="studio.pagination.next"><?= __('studio.pagination.next') ?> &raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>
