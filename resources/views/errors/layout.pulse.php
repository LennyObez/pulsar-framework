<!DOCTYPE html>
<html lang="en" data-theme="@yield('theme', 'light')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') &mdash; @t('errors.go_home')</title>
    <style>
        /* === Reset & Foundation === */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        /* --- Light theme (front-office) --- */
        :root, [data-theme="light"] {
            --bg-primary: #f8fafc;
            --bg-surface: #ffffff;
            --bg-surface-hover: #f1f5f9;
            --bg-code: #e8eefb;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --text-code: #0039cb;
            --border-color: #e2e8f0;
            --border-focus: #0039cb;
            --accent: #0039cb;
            --accent-hover: #002da1;
            --accent-subtle: #e8eefb;
            --accent-text: #ffffff;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --warning: #d97706;
            --warning-bg: #fffbeb;
            --shadow-sm: 0 1px 2px rgba(15,23,42,.06);
            --shadow-md: 0 4px 12px rgba(15,23,42,.08);
            --shadow-lg: 0 12px 40px rgba(15,23,42,.12);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --grain-opacity: 0.03;
        }

        /* --- Dark theme (back-office) --- */
        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-surface: #1e293b;
            --bg-surface-hover: #283548;
            --bg-code: #0f172a;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --text-code: #c5d4f5;
            --border-color: #334155;
            --border-focus: #4a7be0;
            --accent: #4a7be0;
            --accent-hover: #7399e6;
            --accent-subtle: rgba(74,123,224,.15);
            --accent-text: #ffffff;
            --danger: #f87171;
            --danger-bg: rgba(220,38,38,.12);
            --warning: #fbbf24;
            --warning-bg: rgba(245,158,11,.12);
            --shadow-sm: 0 1px 2px rgba(0,0,0,.2);
            --shadow-md: 0 4px 12px rgba(0,0,0,.3);
            --shadow-lg: 0 12px 40px rgba(0,0,0,.4);
            --grain-opacity: 0.04;
        }

        /* --- Typography (inline, no external deps) --- */
        body {
            font-family: 'Overpass', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Subtle grain texture overlay */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            opacity: var(--grain-opacity);
            pointer-events: none;
            z-index: 9999;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
            background-repeat: repeat;
            background-size: 256px 256px;
        }

        h1, h2, h3 {
            font-family: 'Montserrat', system-ui, -apple-system, sans-serif;
            letter-spacing: -0.02em;
        }

        /* === Error Page Structure === */
        .error-page {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
        }

        .error-page__container {
            max-width: 560px;
            width: 100%;
            text-align: center;
        }

        /* --- Status code display --- */
        .error-page__code {
            font-family: 'Montserrat', system-ui, sans-serif;
            font-size: clamp(5rem, 15vw, 9rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.06em;
            color: var(--border-color);
            margin-bottom: 0.5rem;
            user-select: none;
            position: relative;
        }

        /* Accent underline beneath code */
        .error-page__code::after {
            content: '';
            display: block;
            width: 64px;
            height: 4px;
            background: var(--accent);
            margin: 0.75rem auto 0;
            border-radius: 2px;
        }

        /* --- Heading --- */
        .error-page__heading {
            font-size: clamp(1.25rem, 3vw, 1.75rem);
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        /* --- Description --- */
        .error-page__description {
            font-size: 1rem;
            color: var(--text-secondary);
            line-height: 1.7;
            margin-bottom: 2rem;
            max-width: 440px;
            margin-left: auto;
            margin-right: auto;
        }

        /* --- Action buttons --- */
        .error-page__actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .error-page__btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6875rem 1.5rem;
            border-radius: var(--radius-sm);
            font-family: 'Overpass', system-ui, sans-serif;
            font-size: 0.9375rem;
            font-weight: 600;
            text-decoration: none;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
        }

        .error-page__btn:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 2px;
        }

        .error-page__btn--primary {
            background: var(--accent);
            color: var(--accent-text);
            border-color: var(--accent);
        }

        .error-page__btn--primary:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .error-page__btn--primary:active {
            transform: translateY(0);
        }

        .error-page__btn--secondary {
            background: transparent;
            color: var(--accent);
            border-color: var(--accent);
        }

        .error-page__btn--secondary:hover {
            background: var(--accent-subtle);
        }

        .error-page__btn--ghost {
            background: transparent;
            color: var(--text-secondary);
            border-color: var(--border-color);
        }

        .error-page__btn--ghost:hover {
            background: var(--bg-surface-hover);
            border-color: var(--text-muted);
        }

        /* Button icon */
        .error-page__btn svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        /* --- Search bar (for 404) --- */
        .error-page__search {
            position: relative;
            max-width: 400px;
            margin: 0 auto 2rem;
        }

        .error-page__search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            font-family: 'Overpass', system-ui, sans-serif;
            font-size: 0.9375rem;
            background: var(--bg-surface);
            color: var(--text-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .error-page__search-input::placeholder {
            color: var(--text-muted);
        }

        .error-page__search-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-subtle);
        }

        .error-page__search-icon {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: var(--text-muted);
            pointer-events: none;
        }

        /* --- Links grid (for 404) --- */
        .error-page__links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.5rem;
            max-width: 400px;
            margin: 0 auto 2rem;
        }

        .error-page__link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--accent);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .error-page__link:hover {
            background: var(--accent-subtle);
            border-color: var(--accent);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .error-page__link:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 2px;
        }

        /* --- Countdown / timer (for 429, 503) --- */
        .error-page__countdown {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1.5rem;
            background: var(--warning-bg);
            border: 1px solid var(--warning);
            border-radius: var(--radius-md);
            color: var(--warning);
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 2rem;
        }

        .error-page__countdown-value {
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 1.25rem;
            font-weight: 700;
            min-width: 2.5rem;
            text-align: center;
        }

        /* --- Maintenance banner (for 503) --- */
        .error-page__maintenance {
            padding: 1rem 1.5rem;
            background: var(--accent-subtle);
            border: 1px solid var(--accent);
            border-radius: var(--radius-md);
            color: var(--accent);
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 2rem;
            line-height: 1.5;
        }

        .error-page__maintenance strong {
            display: block;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        /* --- Footer --- */
        .error-page__footer {
            text-align: center;
            padding: 1.5rem 2rem;
            color: var(--text-muted);
            font-size: 0.8125rem;
            border-top: 1px solid var(--border-color);
        }

        /* --- Responsive --- */
        @media (max-width: 480px) {
            .error-page { padding: 1.5rem 1rem; }
            .error-page__actions { flex-direction: column; align-items: stretch; }
            .error-page__btn { justify-content: center; }
            .error-page__links { grid-template-columns: 1fr; }
        }

        /* --- Reduced motion --- */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* --- Print --- */
        @media print {
            body::before { display: none; }
            .error-page__actions, .error-page__search, .error-page__links { display: none; }
            .error-page__code { color: #000; }
            body { background: #fff; color: #000; }
        }
    </style>
    @yield('extra-styles')
</head>
<body>
    <a href="#error-content" class="sr-only" data-t="a11y.skip_to_content" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap">Skip to main content</a>

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
