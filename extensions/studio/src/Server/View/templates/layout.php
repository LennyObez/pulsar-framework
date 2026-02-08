<?php
/**
 * @var string|null $title
 * @var string|null $content
 */
$typedTitle = $title ?? 'Pulsar Studio';
$typedContent = $content ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($typedTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/studio/assets/studio.css">
</head>
<body>
    <?= $typedContent ?>
    <script type="module" src="/studio/assets/main.js"></script>
</body>
</html>
