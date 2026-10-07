<?php
/**
 * @var string|null $title
 * @var string|null $message
 * @var string|null $backUrl
 * @var string|null $backLabel
 */
$typedTitle = $title ?? __('studio.no_data');
$typedMessage = $message ?? __('studio.no_data_hint');
$typedBackUrl = $backUrl ?? '';
$typedBackLabel = $backLabel ?? __('studio.go_back');
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
