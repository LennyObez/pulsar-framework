<?php

declare(strict_types=1);

/** @var string $content */
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-extension="analytics">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= __('analytics.nav.title') ?> | Pulsar</title>
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/analytics/assets/analytics.css">
</head>
<body>
    <div class="analytics-layout">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <main class="analytics-main">
            <?= $content ?>
        </main>
    </div>
    <script src="/ui/js/language-selector.js" defer></script>
    <script src="/analytics/assets/dashboard.js" defer></script>
</body>
</html>
