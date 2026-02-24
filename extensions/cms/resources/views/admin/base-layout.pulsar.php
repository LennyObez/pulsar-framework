<!DOCTYPE html>
<html lang="en" data-theme="{{ $_COOKIE['cms_theme'] ?? '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $csrfToken ?? '' }}">
    <title>@yield('title', 'Pulsar CMS')</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    @if (!empty($fontawesomeKitId))
        <script src="https://kit.fontawesome.com/{{ $fontawesomeKitId }}.js" crossorigin="anonymous"></script>
    @endif
    <link rel="stylesheet" href="/admin/cms/assets/dist/cms-admin.css">
    <link rel="stylesheet" href="/admin/cms/assets/dist/cms-admin-dark.css">
    @yield('styles')
</head>
<body>
    <a href="#main-content" class="sr-only sr-only--focusable">Skip to main content</a>
    <div class="cms-admin-shell">
        <header class="cms-header">
            <a href="/admin/cms" class="cms-header__brand">Pulsar CMS</a>
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
    <script type="module" src="/admin/cms/assets/dist/cms-admin.js"></script>
</body>
</html>
