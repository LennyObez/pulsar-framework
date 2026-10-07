<?php

declare(strict_types=1);

/**
 * 404: resource not found.
 *
 * @var string $page_title
 * @var string $message
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<div class="forum-error-page">
    <h1><?= @t('forum.error.404') ?></h1>
    <p><?= $e($message ?? @t('forum.error.not_found')) ?></p>
    <a href="/forum" class="forum-btn forum-btn--primary"><?= @t('forum.error.back_to_forum') ?></a>
</div>
