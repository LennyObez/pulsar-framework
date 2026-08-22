<?php
/**
 * @var string|null $title
 * @var string|null $content
 */
$typedTitle = $title ?? 'Pulsar Studio';
$typedContent = $content ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-extension="studio">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($typedTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/studio/assets/studio.css">
</head>
<body>
    <header class="studio-header" role="banner">
        <span class="studio-header__brand" data-t="studio.title"><?= __('studio.title') ?></span>
        <?php /** @var mixed $rawLocale */ $rawLocale = $locale ?? null; ?>
        <div data-language-selector data-locales="en,fr,nl,de,es,it,pt,pl,ro,cs,el,hu,sv,da,fi,sk,bg,hr,sl,lt,lv,et,ga,mt,lb" data-current="<?= htmlspecialchars(is_string($rawLocale) ? $rawLocale : 'en') ?>"></div>
    </header>
    <?= $typedContent ?>
    <script src="/ui/js/language-selector.js" defer></script>
    <script type="module" src="/studio/assets/main.js"></script>
</body>
</html>
