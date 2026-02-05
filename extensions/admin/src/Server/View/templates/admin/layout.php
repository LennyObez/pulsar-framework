<?php

declare(strict_types=1);

/**
 * @var string $title
 * @var string $content
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> - Pulsar Admin</title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>

    <main class="admin-main" role="main">
        <header class="admin-header">
            <h1><?= $e($title) ?></h1>
        </header>

        <div class="admin-content">
            <?php include __DIR__ . '/' . $content . '.php'; ?>
        </div>
    </main>

    <script type="module" src="/admin/assets/main.js"></script>
</body>
</html>
