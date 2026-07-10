<!DOCTYPE html>
<html lang="en" data-theme="@yield('theme', 'light')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') &mdash; @t('errors.go_home')</title>
    <link rel="stylesheet" href="/ui/css/errors.css">
    @yield('extra-styles')
    <?php echo $pulsarSignature ?? ''; ?>
</head>
<body>
    <a href="#error-content" class="sr-only" data-t="a11y.skip_to_content">Skip to main content</a>

    <main class="error-page" role="main" id="error-content">
        <div class="error-page__container">
            <div class="error-page__code" aria-hidden="true" data-t="errors.<?php echo $status; ?>.title">
                <?php echo $status; ?>
            </div>

            @yield('content')

            <nav class="error-page__actions" aria-label="Error recovery options">
                @yield('actions')
            </nav>

            @yield('extra-content')
        </div>
    </main>

    <footer class="error-page__footer" role="contentinfo">
        @t('powered_by')
    </footer>

    @yield('scripts')
</body>
</html>
