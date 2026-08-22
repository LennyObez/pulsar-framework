<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Safe Mode | Site Under Maintenance</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #1a1a2e;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 2rem;
        }
        .safe-mode {
            max-width: 600px;
            width: 100%;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            padding: 3rem 2.5rem;
            text-align: center;
        }
        .safe-mode__icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }
        .safe-mode__heading {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: #16213e;
        }
        .safe-mode__text {
            font-size: 1rem;
            color: #4a4a68;
            margin-bottom: 1.5rem;
        }
        .safe-mode__admin-link {
            display: inline-block;
            padding: 0.625rem 1.5rem;
            background: #0f3460;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 600;
            transition: background 0.15s ease;
        }
        .safe-mode__admin-link:hover,
        .safe-mode__admin-link:focus {
            background: #16213e;
            outline: 2px solid #0f3460;
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <main class="safe-mode" role="main">
        <span class="safe-mode__icon" aria-hidden="true">&#9888;</span>
        <h1 class="safe-mode__heading">Site Under Maintenance</h1>
        <p class="safe-mode__text">
            The active theme has been disabled due to an integrity issue.
            A minimal fallback template is in use while the problem is resolved.
        </p>
        <a href="/admin/cms/themes" class="safe-mode__admin-link">Go to Theme Management</a>
    </main>
</body>
</html>
