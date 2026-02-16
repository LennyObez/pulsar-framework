<?php

declare(strict_types=1);

/**
 * @var string $title
 * @var string $content
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-extension="admin">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> - <?= __('admin.nav.brand') ?></title>
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-body">
    <a href="#main-content" class="pui-skip-link" data-t="admin.skip_to_content"><?= __('admin.skip_to_content') ?></a>
    <?php include __DIR__ . '/partials/nav.php'; ?>

    <main class="admin-main" id="main-content" role="main">
        <header class="admin-header">
            <h1><?= $e($title) ?></h1>
        </header>

        <div class="admin-content">
            <?php include __DIR__ . '/' . $content . '.php'; ?>
        </div>
    </main>

    <script src="/ui/js/language-selector.js" defer></script>
    <script type="module" src="/admin/assets/main.js"></script>
</body>
</html>
