<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome to Pulsar CMS</title>
    <meta name="description" content="Pulsar CMS is running. Open the admin panel to create and manage your content.">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1e293b;min-height:100vh;display:flex;align-items:center;justify-content:center}
        .welcome{max-width:640px;padding:3rem 2rem;text-align:center}
        .welcome__logo{font-size:2.5rem;font-weight:800;color:#2563eb;letter-spacing:-0.02em;margin-bottom:0.5rem}
        .welcome__subtitle{font-size:1.125rem;color:#64748b;margin-bottom:2.5rem}
        .welcome__card{background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem;padding:2rem;text-align:left;margin-bottom:2rem}
        .welcome__card h2{font-size:1.125rem;font-weight:600;margin-bottom:1rem;color:#0f172a}
        .welcome__card ol{padding-left:1.5rem;line-height:1.8;color:#475569}
        .welcome__card code{background:#f1f5f9;padding:0.125rem 0.375rem;border-radius:0.25rem;font-size:0.875rem;color:#0f172a}
        .welcome__actions{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap}
        .welcome__btn{display:inline-flex;align-items:center;padding:0.75rem 1.5rem;border-radius:0.5rem;font-weight:600;font-size:0.875rem;text-decoration:none;transition:background 0.15s}
        .welcome__btn--primary{background:#2563eb;color:#fff}
        .welcome__btn--primary:hover{background:#1d4ed8}
        .welcome__btn--secondary{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}
        .welcome__btn--secondary:hover{background:#e2e8f0}
        .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}
        .sr-only--focusable:focus-visible{position:fixed;top:0;left:0;width:auto;height:auto;padding:0.75rem 1.5rem;margin:0;overflow:visible;clip:auto;white-space:normal;background:#2563eb;color:#fff;font-weight:700;font-size:0.875rem;z-index:10000;border-radius:0 0 0.5rem 0}
    </style>
</head>
<body>
    <a href="#main-content" class="sr-only sr-only--focusable">Skip to main content</a>
    <div class="welcome" id="main-content">
        <h1 class="welcome__logo">Pulsar CMS</h1>
        <p class="welcome__subtitle">Your content management system is running.</p>
        <div class="welcome__card">
            <h2>Quick Start</h2>
            <ol>
                <li>Open the <strong>admin panel</strong> to create your first content</li>
                <li>Define content types, taxonomies, and menus</li>
                <li>Publish content and it will appear at this URL</li>
            </ol>
        </div>
        <div class="welcome__actions">
            <a href="/admin/cms" class="welcome__btn welcome__btn--primary">Open Admin Panel</a>
            <a href="/admin/cms/content" class="welcome__btn welcome__btn--secondary">Create Content</a>
        </div>
    </div>
</body>
</html>
