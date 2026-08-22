# Health status

The `pulsar/health-status` extension records health-check snapshots to a durable
store, detects incidents, and serves an operational dashboard. It also exposes a
compact, site-wide status indicator for a footer or header.

## Site-wide status pill

`StatusPillProvider` distills the recorded history into a single
`StatusPill { status, label }` for a footer/header badge, so a site does not have
to re-derive "what's the overall status right now" per page:

```php
use Pulsar\Extension\HealthStatus\StatusPillProvider;

$pill = $container->get(StatusPillProvider::class)->pill();
// $pill->status: 'operational' data value — 'unknown' | 'healthy' | 'degraded' | 'unhealthy'
// $pill->label:  'Unknown' | 'Operational' | 'Degraded' | 'Outage'
```

```html
<span class="status-pill status-pill--{{ $pill->status }}">{{ $pill->label }}</span>
```

### Behaviour

- **Worst-of-window.** The pill reports the **worst** overall status recorded in
  the last 24 hours (configurable), so a single degraded snapshot during the day
  is not hidden by a later healthy one.
- **Cached.** The result is cached (60 s by default) so a footer on every page
  does not re-query the history store.
- **Fails open to neutral.** With no recorded history, or on any store/cache
  error, the pill is `unknown` — never a false `healthy`/`operational`. A green
  pill therefore always reflects real, recent, successful checks.

Snapshots are written by the extension's scheduled `HealthCheckSnapshotJob` and
pruned by `HistoryCleanupJob`; the pill reads whatever history is present.

## Durable history

The `HealthHistoryStoreInterface` (default: `DatabaseHealthHistoryStore`) persists
each snapshot and supports `recentSnapshots()`, `snapshotsBetween()`, incident
storage, and retention cleanup — the same history the dashboard and the status
pill read from.
