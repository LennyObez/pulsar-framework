<?php
$typedTitle = isset($title) && is_string($title) ? $title : 'System Status';
$typedContent = isset($content) && is_string($content) ? $content : '';
$currentYear = (int) date('Y');
$timestamp = date('Y-m-d\TH:i:sP');
$displayTime = date('M j, Y H:i:s T');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-extension="health-status">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($typedTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/health-status/assets/health-status.css">
</head>
<body>
    <a href="#main-content" class="pui-skip-link">Skip to content</a>

    <header class="status-header" role="banner">
        <div>
            <span class="status-header__brand">Pulsar</span>
            <span class="status-header__title">System Status</span>
        </div>
    </header>

    <main id="main-content" class="status-content" role="main">
        <?= $typedContent ?>
    </main>

    <footer class="status-footer" role="contentinfo">
        <span class="status-footer__powered">Powered by Pulsar</span>
        <time class="status-footer__timestamp" datetime="<?= htmlspecialchars($timestamp, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"><?= htmlspecialchars($displayTime, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></time>
    </footer>
</body>
</html>
