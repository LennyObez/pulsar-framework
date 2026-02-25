# Getting Started with Pulsar Analytics

This guide walks you through installing and configuring the Pulsar Analytics extension, adding the tracker to your site, and viewing your first dashboard.

## Prerequisites

- A working Pulsar Framework application (v1.0.0+)
- PHP 8.5 or later
- Composer installed
- A supported database (PostgreSQL, MySQL, or SQLite)

## Step 1: Install the Analytics Extension

The Analytics extension ships with the Pulsar framework. Enable it by registering it in your application's extension configuration.

Add the extension to your `config/extensions.php`:

```php
<?php

declare(strict_types=1);

return [
    'extensions' => [
        \Pulsar\Extension\Analytics\AnalyticsExtension::class,
    ],
];
```

## Step 2: Create the Configuration File

Create `config/analytics.php` in your project's configuration directory:

```php
<?php

declare(strict_types=1);

return [
    'enabled' => true,

    'collection' => [
        'driver' => 'direct', // 'direct' or 'queue'
    ],

    'trusted_proxies' => [
        // IP addresses of your reverse proxies (nginx, Cloudflare, etc.)
        // '10.0.0.1',
    ],

    'privacy' => [
        'respect_dnt' => false,
        'anonymize_referrer' => false,
    ],

    'tracking' => [
        'tracker_endpoint' => '/plsr/api/event',
        'script_endpoint' => '/plsr/js/tracker.js',
        'extensions' => [],
    ],

    'retention' => [
        'raw_days' => 90,
        'aggregated_days' => 730,  // 2 years
        'hourly_hours' => 48,
    ],

    'rate_limit' => [
        'max_events_per_ip_per_minute' => 30,
        'burst' => 5,
    ],
];
```

### Configuration Reference

| Key                                       | Type     | Default                 | Description                                        |
| ----------------------------------------- | -------- | ----------------------- | -------------------------------------------------- |
| `enabled`                                 | bool     | `true`                  | Enable or disable analytics collection             |
| `collection.driver`                       | string   | `'direct'`              | Collection mode: `'direct'` (sync) or `'queue'`    |
| `trusted_proxies`                         | string[] | `[]`                    | Reverse proxy IPs trusted for X-Forwarded-For      |
| `privacy.respect_dnt`                     | bool     | `false`                 | Skip tracking for visitors with DNT header         |
| `privacy.anonymize_referrer`              | bool     | `false`                 | Strip query strings from referrer URLs             |
| `tracking.tracker_endpoint`               | string   | `'/plsr/api/event'`     | URL path for the collection endpoint               |
| `tracking.script_endpoint`                | string   | `'/plsr/js/tracker.js'` | URL path for the tracker JavaScript                |
| `tracking.extensions`                     | string[] | `[]`                    | Tracker extension modules (e.g., `'spa'`)          |
| `retention.raw_days`                      | int      | `90`                    | Days to keep raw page view and event data          |
| `retention.aggregated_days`               | int      | `730`                   | Days to keep pre-aggregated daily stats            |
| `retention.hourly_hours`                  | int      | `48`                    | Hours to keep hourly rollup data                   |
| `rate_limit.max_events_per_ip_per_minute` | int      | `30`                    | Maximum events accepted per IP per minute          |
| `rate_limit.burst`                        | int      | `5`                     | Additional burst capacity above the sustained rate |

## Step 3: Run Migrations

Run the analytics database migrations to create the required tables:

```bash
php bin/pulsar migrate
```

This creates 12 tables: sites, page views, sessions, events, goals, goal conversions, daily stats, hourly stats, and four daily breakdown tables (pages, referrers, devices, locations).

## Step 4: Register a Site

Before tracking, register the site you want to monitor. Navigate to the analytics dashboard at:

```
https://your-site.com/analytics/sites
```

Or use the API:

```bash
curl -X POST https://your-site.com/plsr/api/v1/sites \
  -H "Content-Type: application/json" \
  -d '{"domain": "example.com", "name": "My Website"}'
```

The response includes a `trackingId` (e.g., `plsr_a1b2c3d4`), which you'll use in the tracker script.

## Step 5: Add the Tracker Script

Add the tracker script to your website's HTML, just before the closing `</body>` tag:

```html
<script
  defer
  data-site="plsr_a1b2c3d4"
  data-api="https://your-site.com/plsr/api/event"
  src="https://your-site.com/plsr/js/tracker.js"
></script>
```

Replace `plsr_a1b2c3d4` with your actual tracking ID, and update the domain to match your Pulsar application.

### Script Attributes

| Attribute         | Required | Description                                              |
| ----------------- | -------- | -------------------------------------------------------- |
| `data-site`       | Yes      | Your site's tracking ID                                  |
| `data-api`        | Yes      | Full URL of the collection endpoint                      |
| `data-extensions` | No       | Comma-separated list of tracker extensions (e.g., `spa`) |

### Do Not Track

When `privacy.respect_dnt` is enabled in the config, the tracker automatically checks the browser's DNT (Do Not Track) setting and skips all tracking for visitors who have it enabled.

## Step 6: View the Dashboard

Navigate to the analytics dashboard at:

```
https://your-site.com/analytics
```

You must be authenticated with an account that has the `analytics.view` permission.

The dashboard shows:

- **Visitors**: Unique visitors (privacy-preserving daily rotation)
- **Page views**: Total page views
- **Sessions**: Visitor sessions (30-minute inactivity window)
- **Bounce rate**: Percentage of single-page sessions
- **Average duration**: Mean session duration in seconds
- **Top pages**: Most visited pages
- **Top referrers**: Traffic sources
- **Devices**: Browser, OS, and device type breakdown
- **Locations**: Country-level visitor distribution

## Step 7: Track Custom Events

The tracker script exposes a global `window.plsr` object for custom events:

```javascript
// Track a button click
plsr.event('signup_click', { plan: 'pro' });

// Track a purchase with revenue
plsr.event('purchase', { product: 'widget' }, 29.99);
```

Custom events are visible in the dashboard and can be used to create goals.

## Reverse Proxy Setup

If your application runs behind a reverse proxy (nginx, Cloudflare, AWS ALB), configure trusted proxies so that visitor IP addresses are resolved correctly:

```php
'trusted_proxies' => [
    '10.0.0.1',       // Load balancer
    '172.16.0.0/12',  // Internal network
],
```

Without this, all visitors appear as the proxy's IP address, which breaks visitor counting and rate limiting.

## Queue-Based Collection

For high-traffic sites, switch to queue-based collection to offload tracking from the HTTP request cycle:

```php
'collection' => [
    'driver' => 'queue',
],
```

Page views and events are dispatched as background jobs and processed asynchronously.

## Next Steps

- [Dashboard Guide](dashboard-guide.md) - Detailed dashboard features and filters
- [Goals Guide](goals-guide.md) - Setting up conversion goals
- [API Reference](../developer/api-reference.md) - Complete API endpoint reference
- [Architecture](../developer/architecture.md) - Technical architecture overview
- [Privacy & Security](../security/privacy-model.md) - Privacy model and data handling
