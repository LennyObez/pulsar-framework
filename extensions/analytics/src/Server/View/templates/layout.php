<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics — Pulsar</title>
    <link rel="stylesheet" href="/analytics/assets/analytics.css">
</head>
<body data-theme="auto">
    <div class="analytics-layout">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <main class="analytics-main">
            <?= $content ?? '' ?>
        </main>
    </div>
    <script src="/analytics/assets/dashboard.js" defer></script>
</body>
</html>
