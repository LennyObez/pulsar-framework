# API endpoint reference

All Analytics API endpoints are registered by `AnalyticsExtension::boot()`. API and dashboard endpoints require authentication and the `analytics.view` permission. Collection and tracker endpoints are public.

## Public endpoints

### Collection endpoint

| Method | Path              | Route Name          | Auth | Description                         |
| ------ | ----------------- | ------------------- | ---- | ----------------------------------- |
| POST   | `/plsr/api/event` | `analytics.collect` | No   | Receive page view and event beacons |

**Middleware stack**: `CollectionRateLimitMiddleware` > `CollectionCorsMiddleware` > `BotFilterMiddleware`

**Request Body** (JSON):

```json
{
  "type": "pageview",
  "site": "plsr_a1b2c3d4",
  "url": "https://example.com/docs/getting-started",
  "referrer": "https://google.com",
  "screen_width": 1920
}
```

| Field           | Type   | Required | Description                                 |
| --------------- | ------ | -------- | ------------------------------------------- |
| `type`          | string | Yes      | Event type: `"pageview"` or `"event"`       |
| `site`          | string | Yes      | Site tracking ID                            |
| `url`           | string | Yes\*    | Current page URL (\*required for pageviews) |
| `referrer`      | string | No       | Referrer URL                                |
| `screen_width`  | int    | No       | Viewport width in pixels                    |
| `event_name`    | string | Yes\*    | Event name (\*required for custom events)   |
| `event_props`   | object | No       | Custom event properties                     |
| `revenue_value` | float  | No       | Revenue value for conversion tracking       |

**Response**: Always `204 No Content`. Never leaks information about whether tracking succeeded or failed.

**Origin validation**: The `Origin` or `Referer` header must match the registered site domain. Requests without both headers are rejected.

### Tracker script

| Method   | Path                  | Route Name          | Auth | Description                  |
| -------- | --------------------- | ------------------- | ---- | ---------------------------- |
| GET/HEAD | `/plsr/js/tracker.js` | `analytics.tracker` | No   | Serve the tracker JavaScript |

**Response headers**:

| Header                   | Value                                   |
| ------------------------ | --------------------------------------- |
| `Content-Type`           | `application/javascript; charset=utf-8` |
| `Cache-Control`          | `public, max-age=86400`                 |
| `ETag`                   | xxh3 hash of the script content         |
| `X-Content-Type-Options` | `nosniff`                               |

Supports `If-None-Match` for `304 Not Modified` responses.

## Authenticated API endpoints

All endpoints below require authentication and the `analytics.view` permission. Prefix: `/plsr/api/v1`.

### Stats

| Method   | Path                            | Route Name                 | Description             |
| -------- | ------------------------------- | -------------------------- | ----------------------- |
| GET/HEAD | `/plsr/api/v1/stats/aggregate`  | `analytics.api.stats`      | Aggregate statistics    |
| GET/HEAD | `/plsr/api/v1/stats/timeseries` | `analytics.api.timeseries` | Time series data points |
| GET/HEAD | `/plsr/api/v1/stats/breakdown`  | `analytics.api.breakdown`  | Breakdown by dimension  |
| GET/HEAD | `/plsr/api/v1/stats/realtime`   | `analytics.api.realtime`   | Real-time visitor count |

**Common query parameters**:

| Parameter | Type   | Required | Default    | Description                              |
| --------- | ------ | -------- | ---------- | ---------------------------------------- |
| `site_id` | string | Yes      | --         | Site identifier                          |
| `from`    | string | No       | `-30 days` | Start date (ISO 8601 or relative)        |
| `to`      | string | No       | `now`      | End date (ISO 8601 or relative)          |
| `period`  | string | No       | `day`      | Grouping period (`hour`, `day`, `month`) |

### Export

| Method   | Path                  | Route Name             | Description         |
| -------- | --------------------- | ---------------------- | ------------------- |
| GET/HEAD | `/plsr/api/v1/export` | `analytics.api.export` | Export raw data CSV |

**Query parameters**:

| Parameter | Type   | Required | Default    | Description     |
| --------- | ------ | -------- | ---------- | --------------- |
| `site_id` | string | Yes      | --         | Site identifier |
| `from`    | string | No       | `-30 days` | Start date      |
| `to`      | string | No       | `now`      | End date        |

**Response**: CSV file download with formula injection protection. Maximum 10,000 rows per export.

### Goals CRUD

| Method   | Path                      | Route Name                   | Description      |
| -------- | ------------------------- | ---------------------------- | ---------------- |
| GET/HEAD | `/plsr/api/v1/goals`      | `analytics.api.goals.index`  | List all goals   |
| POST     | `/plsr/api/v1/goals`      | `analytics.api.goals.create` | Create a goal    |
| GET/HEAD | `/plsr/api/v1/goals/{id}` | `analytics.api.goals.show`   | Get goal details |
| PUT      | `/plsr/api/v1/goals/{id}` | `analytics.api.goals.update` | Update a goal    |
| DELETE   | `/plsr/api/v1/goals/{id}` | `analytics.api.goals.delete` | Delete a goal    |

### Sites CRUD

| Method   | Path                      | Route Name                   | Description          |
| -------- | ------------------------- | ---------------------------- | -------------------- |
| GET/HEAD | `/plsr/api/v1/sites`      | `analytics.api.sites.index`  | List all sites       |
| POST     | `/plsr/api/v1/sites`      | `analytics.api.sites.create` | Register a new site  |
| GET/HEAD | `/plsr/api/v1/sites/{id}` | `analytics.api.sites.show`   | Get site details     |
| PUT      | `/plsr/api/v1/sites/{id}` | `analytics.api.sites.update` | Update site settings |
| DELETE   | `/plsr/api/v1/sites/{id}` | `analytics.api.sites.delete` | Delete a site        |

## Dashboard endpoints

All dashboard endpoints serve HTML pages and require authentication.

| Method   | Path                       | Route Name                     | Auth | Description                   |
| -------- | -------------------------- | ------------------------------ | ---- | ----------------------------- |
| GET/HEAD | `/analytics`               | `analytics.dashboard`          | Yes  | Main dashboard                |
| GET/HEAD | `/analytics/sites`         | `analytics.dashboard.sites`    | Yes  | Sites management              |
| GET/HEAD | `/analytics/goals`         | `analytics.dashboard.goals`    | Yes  | Goals management              |
| GET/HEAD | `/analytics/settings`      | `analytics.dashboard.settings` | Yes  | Analytics settings            |
| GET/HEAD | `/analytics/assets/{path}` | `analytics.assets`             | No   | Static assets (CSS/JS/images) |

## Common response formats

### Error response

```json
{
  "error": "site_id is required"
}
```

### Common status codes

| Code | Meaning                  |
| ---- | ------------------------ |
| 200  | Success                  |
| 204  | No content (collection)  |
| 304  | Not modified (tracker)   |
| 400  | Validation error         |
| 401  | Authentication required  |
| 403  | Insufficient permissions |
| 404  | Resource not found       |

## Related documentation

- [Architecture Overview](architecture.md) - Data flow and boot sequence
- [Getting Started](../user/getting-started.md) - Installation and setup
- [Privacy Model](../security/privacy-model.md) - Data handling and compliance
