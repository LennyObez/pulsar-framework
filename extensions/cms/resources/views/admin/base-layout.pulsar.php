<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Pulsar CMS')</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    @if (!empty($fontawesomeKitId))
        <script src="https://kit.fontawesome.com/{{ $fontawesomeKitId }}.js" crossorigin="anonymous"></script>
    @endif
    <link rel="stylesheet" href="/admin/cms/assets/cms-admin.css">
    @yield('styles')
</head>
<body>
    <a href="#main-content" class="sr-only sr-only--focusable">Skip to main content</a>
    <div class="cms-admin-shell">
        <header class="cms-header">
            <a href="/admin/cms" class="cms-header__brand">Pulsar CMS</a>
        </header>

        <aside class="cms-sidebar">
            @yield('sidebar')
        </aside>

        <main class="cms-main" id="main-content">
            @yield('content')
        </main>
    </div>
</body>
</html>
