<!DOCTYPE html>
<html lang="en" data-theme="{{ in_array($_COOKIE['cms_theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['cms_theme'] : '' }}" data-extension="cms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $csrfToken ?? '' }}">
    <title>@yield('title', 'Pulsar CMS')</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    @if (!empty($fontawesomeKitId))
        <script src="https://kit.fontawesome.com/{{ $fontawesomeKitId }}.js" crossorigin="anonymous"></script>
    @endif
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/admin/cms/assets/dist/cms-admin.css">
    @yield('styles')
</head>
<body>
    <a href="#main-content" class="pui-skip-link">Skip to main content</a>
    <div class="cms-admin-shell">
        <header class="cms-header">
            <a href="/admin/cms" class="cms-header__brand">Pulsar CMS</a>
            <div data-language-selector data-locales="en,fr,nl,de,es,it,pt,pl,ro,cs,el,hu,sv,da,fi,sk,bg,hr,sl,lt,lv,et,ga,mt,lb" data-current="{{ htmlspecialchars($locale ?? 'en') }}"></div>
            <cms-theme-toggle></cms-theme-toggle>
        </header>

        <aside class="cms-sidebar">
            @yield('sidebar')
        </aside>

        <main class="cms-main" id="main-content">
            @yield('content')
        </main>
    </div>
    <cms-toast-container></cms-toast-container>
    <cms-command-palette></cms-command-palette>
    <script src="/ui/js/language-selector.js" defer></script>
    <script type="module" src="/admin/cms/assets/dist/cms-admin.js"></script>
</body>
</html>
