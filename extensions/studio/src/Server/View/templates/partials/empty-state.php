<?php
/**
 * @var string|null $title
 * @var string|null $message
 * @var string|null $backUrl
 * @var string|null $backLabel
 */
$typedTitle = $title ?? 'No Data';
$typedMessage = $message ?? 'No data to display.';
$typedBackUrl = $backUrl ?? '';
$typedBackLabel = $backLabel ?? 'Go Back';
?>
<div class="empty-state">
    <h2><?= htmlspecialchars($typedTitle, ENT_QUOTES, 'UTF-8') ?></h2>
    <p><?= htmlspecialchars($typedMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($typedBackUrl !== ''): ?>
        <a href="<?= htmlspecialchars($typedBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn">
            <?= htmlspecialchars($typedBackLabel, ENT_QUOTES, 'UTF-8') ?>
        </a>
    <?php endif; ?>
</div>
