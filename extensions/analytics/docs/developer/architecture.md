# Analytics extension architecture

The Pulsar Analytics extension provides privacy-focused, cookie-free web analytics. It is organized into clearly separated layers with strict module boundaries.

## Module map

| Layer         | Namespace            | Responsibility                                                   |
| ------------- | -------------------- | ---------------------------------------------------------------- |
| **Config**    | `Config\`            | Configuration DTOs with sensible defaults                        |
| **Domain**    | `Domain\`            | Value objects: Site, PageView, Session, CustomEvent, Goal, etc.  |
| **Contracts** | `Contracts\`         | Public service and repository interfaces (`#[Api]`)              |
| **Internal**  | `Internal\`          | Implementation details: services, repositories, middleware, etc. |
| **Server**    | `Server\Controller\` | HTTP controllers for API, dashboard, and collection              |
| **Migration** | `Migration\`         | Database migrations and multi-driver DDL adapter                 |

## Extension entry point

The extension is registered via `AnalyticsExtension`, which implements three lifecycle interfaces:

```
ExtensionInterface          -- name(), register(), boot(), providers()
PreBootExtensionInterface   -- preBoot()
PostBootExtensionInterface  -- postBoot()
```

Source: `extensions/analytics/src/AnalyticsExtension.php`

## Boot sequence (4 phases)

### Phase 1: service provider registration

`AnalyticsServiceProvider::register()` binds all dependencies:

1. **Security**: `AnalyticsKeyManager` (KDF-derived visitor hashing keys)
2. **Pure services**: `BotDetector`, `ReferrerParser`, `UserAgentParser`
3. **Geo resolver**: `DbIpLiteResolver` (DB-IP Lite CSV, binary search)
4. **Repositories**: 8 repository bindings (sites, page views, sessions, events, goals, conversions, daily stats, hourly stats)
5. **Services**: `SessionResolver`, `TrackingService`, `AggregationService`, `StatsService`, `GoalService`, `SiteService`
6. **Middleware**: `CollectionRateLimitMiddleware`, `CollectionCorsMiddleware`, `BotFilterMiddleware`, `AnalyticsAuthMiddleware`
7. **Controllers**: All 10 HTTP controllers

### Phase 2: pre-boot

Loads `config/analytics.php` into an `AnalyticsConfig` DTO. Falls back to defaults if no config file exists.

### Phase 3: boot (route registration)

Registers three route groups:

- **Public routes**: Collection endpoint (`POST /plsr/api/event`) and tracker script (`GET /plsr/js/tracker.js`)
- **API routes**: Authenticated JSON API under `/plsr/api/v1/` for stats, timeseries, breakdowns, realtime, export, goals CRUD, and sites CRUD
- **Dashboard routes**: Authenticated HTML pages under `/analytics/`

### Phase 4: post-boot

Registers scheduled jobs if the `JobRegistryInterface` is available:

| Job                       | Schedule | Purpose                                  |
| ------------------------- | -------- | ---------------------------------------- |
| `AggregationJob`          | Hourly   | Roll up raw data into hourly/daily stats |
| `RetentionCleanupJob`     | Daily    | Purge data beyond retention window       |
| `PartitionMaintenanceJob` | Weekly   | Database partition maintenance           |

## Configuration architecture

All configuration flows through `AnalyticsConfig`, a readonly DTO loaded from `config/analytics.php`:

```
AnalyticsConfig
 +-- enabled: bool               (true)
 +-- collectionDriver: string    ("direct")
 +-- trustedProxies: list<string> ([])
 +-- privacy: PrivacyConfig
 |    +-- respectDnt: bool       (false)
 |    +-- anonymizeReferrer: bool (false)
 +-- tracking: TrackingConfig
 |    +-- trackerEndpoint: string ("/plsr/api/event")
 |    +-- scriptEndpoint: string  ("/plsr/js/tracker.js")
 |    +-- extensions: list<string> ([])
 +-- retention: RetentionConfig
 |    +-- rawDays: int           (90)
 |    +-- aggregatedDays: int    (730)
 |    +-- hourlyHours: int       (48)
 +-- rateLimit: RateLimitConfig
      +-- maxEventsPerIpPerMinute: int (30)
      +-- burst: int                   (5)
```

Every sub-config uses the same pattern: a `readonly` class with a `fromArray()` static factory. All values have sensible defaults.

## Data flow: page view pipeline

```
  Browser sends beacon to /plsr/api/event
         |
         v
  CollectionRateLimitMiddleware -- rate limit by IP
         |
         v
  CollectionCorsMiddleware -- validate Origin header
         |
         v
  BotFilterMiddleware -- reject known bots
         |
         v
  CollectionController::collect()
    1. Parse JSON payload
    2. Validate Origin/Referer against registered site domain
    3. Pass validated Site via request attribute
         |
         v
  TrackingService::trackPageView()
    1. Resolve site (from request attribute or DB fallback)
    2. Bot detection (UA + header heuristics)
    3. DNT check (if enabled)
    4. Client IP extraction (trusted proxy validation)
    5. Visitor ID generation (HMAC keyed by the disposable per-day salt)
    6. Session resolution (30-min inactivity window + midnight grace)
    7. Referrer parsing
    8. User-agent parsing (browser, OS, device type)
    9. GeoIP resolution (country-level, binary search on CSV)
   10. Persist PageView
```

## Visitor identification

Visitors are identified without cookies using a forward-secure HMAC keyed by a **disposable per-day salt** (not a key derived from the master key):

```
salt_day   = 32 random bytes, generated once per UTC day, stored in
             analytics_visitor_salts, deleted after retention by VisitorSaltPurgeJob
visitor_id = keyed BLAKE2b(
                 key:  salt_day,
                 data: ip + "|" + user_agent + "|" + utc_day_number
             )
```

- **Disposable daily salt**: each UTC day gets a fresh random salt, minted atomically on first use and destroyed after `privacy.visitor_salt_retention_days` (default 2). Once a day's salt is purged, its hashes are unrecomputable even with the master key — forward secrecy. See [privacy model](../security/privacy-model.md).
- **Consent hash is separate**: the only stable master-key-derived key (subkey 20, context `"anal_vis"`) now backs the consent hash, which must persist across days; visitor tracking does not use it.
- **No persistence on the client**: nothing is stored on the visitor's device (no cookies, no localStorage)

### Midnight grace period

When a page view arrives in the first 30 minutes of a new UTC day, the `SessionResolver` checks for active sessions using both today's and yesterday's visitor hash. This prevents artificial session breaks at midnight.

## Session management

Sessions use a 30-minute inactivity window:

1. Look for an active session for today's visitor (activity within last 30 minutes)
2. If within the midnight grace period, also check yesterday's visitor hash
3. If found, continue the session (update page count, exit page, duration)
4. If not found, create a new session and persist immediately (TOCTOU prevention)

## Aggregation pipeline

Raw data rolls up through two levels:

```
  Raw page_views / sessions / events
         |
         v (hourly cron)
  Hourly stats (retained 48 hours)
         |
         v (hourly cron)
  Daily stats + breakdown tables (retained 2 years)
```

Daily aggregation queries raw data directly (not hourly stats) to produce mathematically correct results:

- **Visitors**: `COUNT(DISTINCT visitor_id)` over the full day (not sum of hourly counts)
- **Bounce rate**: Total bounced sessions / total sessions (weighted, not averaged)
- **Duration**: `AVG(duration_seconds)` over all sessions (weighted, not averaged)

### Breakdown tables

Four daily breakdown tables provide drill-down analytics:

| Table                       | Dimensions                              |
| --------------------------- | --------------------------------------- |
| `analytics_daily_pages`     | site_id, date, pathname                 |
| `analytics_daily_referrers` | site_id, date, referrer_source          |
| `analytics_daily_devices`   | site_id, date, device_type, browser, os |
| `analytics_daily_locations` | site_id, date, country_code, region     |

All use driver-specific upsert SQL (MySQL `ON DUPLICATE KEY`, PostgreSQL `ON CONFLICT ... EXCLUDED`, SQLite `ON CONFLICT ... excluded`).

## Database schema

### Core tables

| Table                        | Purpose                     | Key Indexes                                                |
| ---------------------------- | --------------------------- | ---------------------------------------------------------- |
| `analytics_sites`            | Registered websites         | `tracking_id` (unique), `domain` (unique)                  |
| `analytics_page_views`       | Raw page view events        | `(site_id, created_at)`, `(site_id, visitor_id)`           |
| `analytics_sessions`         | Visitor sessions            | `(site_id, session_id)`, `(site_id, visitor_id, ended_at)` |
| `analytics_events`           | Custom events               | `(site_id, created_at)`, `(site_id, event_name)`           |
| `analytics_goals`            | Conversion goal definitions | `(site_id)`                                                |
| `analytics_goal_conversions` | Goal conversion records     | `(goal_id, created_at)`                                    |
| `analytics_stats_daily`      | Daily aggregate statistics  | `(site_id, date)`                                          |
| `analytics_stats_hourly`     | Hourly rollup statistics    | `(site_id, date, hour)`                                    |
| `analytics_daily_pages`      | Daily page-level breakdown  | `(site_id, date, pathname)`                                |
| `analytics_daily_referrers`  | Daily referrer breakdown    | `(site_id, date, referrer_source)`                         |
| `analytics_daily_devices`    | Daily device breakdown      | `(site_id, date, device_type, browser, os)`                |
| `analytics_daily_locations`  | Daily location breakdown    | `(site_id, date, country_code, region)`                    |

## Integration map

### Pulsar core dependencies

| Pulsar Module | Analytics Usage                                           |
| ------------- | --------------------------------------------------------- |
| `Container`   | `ContainerInterface` for dependency injection             |
| `Config`      | `ConfigManagerInterface` to locate `config/analytics.php` |
| `Routing`     | `RouterInterface` for HTTP route registration             |
| `Database`    | `ConnectionInterface` for all persistence                 |
| `Auth`        | `GateInterface` for permission-based authorization        |
| `Cache`       | `CacheDriverInterface` for rate limiting                  |
| `Security`    | `MasterKey` for cryptographic key derivation              |
| `Scheduler`   | `JobRegistryInterface` for cron job registration          |
| `Queue`       | Job dispatching for queue-based collection                |
| `Http`        | PSR-7 request/response, middleware pipeline               |

## GeoIP resolution

Country-level geo resolution uses the DB-IP Lite database (CC-BY-4.0 license):

- **Algorithm**: Binary search on the CSV file by byte offset
- **Memory**: O(1) -- file handle opened once, reused across lookups
- **Fallback**: Returns `null` when the database file is not available
- **IPv4 only**: IPv6 support requires a separate lookup table

## Observability

The extension integrates with Pulsar's observability stack through:

- **Structured logging**: All errors in the tracking pipeline are caught and silently handled (never leaked to clients)
- **Metrics**: Page view counts, session counts, and event counts are available via the stats API
- **Scheduled jobs**: Aggregation, retention cleanup, and partition maintenance run on configurable schedules
