# Analytics Privacy & Security Model

Pulsar Analytics is designed from the ground up for privacy compliance. It collects no personal data, uses no cookies, and produces no cross-site tracking capabilities.

## Privacy by Design

### No Cookies

The tracker script does not set, read, or require any cookies. No data is stored on the visitor's device (no cookies, no localStorage, no IndexedDB, no fingerprinting).

### No Personal Data

Visitor identification uses a one-way HMAC that cannot be reversed to recover the original IP address or user agent:

```
visitor_id = HMAC-BLAKE2b(
    key: KDF(master_key, subkey_id=20, context="anal_vis"),
    data: ip + "|" + user_agent + "|" + utc_day_number
)
```

The raw IP address and user agent are never stored. Only the irreversible hash is persisted.

### Daily Key Rotation

Visitor hashes include the UTC day number (`timestamp / 86400`), which means:

- Visitor IDs change every day at UTC midnight
- It is impossible to track a visitor across days
- Historical data cannot be correlated to identify returning visitors
- The same visitor generates different IDs on different days

### No Cross-Site Tracking

Each site has its own tracking ID and domain validation. There is no mechanism to correlate visitors across different sites, even if they share the same Pulsar installation.

## Cryptographic Architecture

### Key Derivation

All cryptographic keys are derived from the application's master key using libsodium's KDF:

```
master_key (PULSAR_MASTER_KEY env var)
    |
    +-- sodium_crypto_kdf_derive_from_key(subkey_id=20, context="anal_vis")
         |
         +-- visitor_key (used for HMAC computation)
```

- **Master key**: 256-bit, stored in `PULSAR_MASTER_KEY` environment variable
- **Subkey derivation**: `sodium_crypto_kdf_derive_from_key()` with 8-byte context
- **HMAC**: BLAKE2b via `Pulsar\Security\Crypto\Hmac::computeHex()`

### Key Properties

| Property          | Value                                    |
| ----------------- | ---------------------------------------- |
| Master key size   | 256 bits                                 |
| Subkey ID         | 20                                       |
| KDF context       | `anal_vis` (8 bytes, per libsodium spec) |
| Hash algorithm    | BLAKE2b (via HMAC)                       |
| Visitor ID format | 64-character hex string                  |

## Authentication & Authorization

### Dashboard and API Access

All dashboard and API endpoints (except collection and tracker) are protected by `AnalyticsAuthMiddleware`:

1. **Authentication check**: Requires a non-anonymous `IdentityInterface` on the request
2. **Authorization check**: Delegates to the framework's `GateInterface` (RBAC + ABAC) with the `analytics.view` permission

### Collection Endpoint Security

The public collection endpoint uses a layered defense:

| Layer                           | Protection                                           |
| ------------------------------- | ---------------------------------------------------- |
| `CollectionRateLimitMiddleware` | IP-based rate limiting (configurable per-minute cap) |
| `CollectionCorsMiddleware`      | Origin validation against registered site domains    |
| `BotFilterMiddleware`           | Bot detection via UA patterns and header heuristics  |
| Origin validation               | Origin/Referer header must match registered domain   |
| Trusted proxy validation        | X-Forwarded-For only trusted from configured proxies |

### Origin Validation

Every collection request must include a valid `Origin` or `Referer` header matching the registered site domain:

- Exact domain match: `example.com`
- Subdomain match: `blog.example.com` matches `example.com`
- Missing both headers: **rejected** (prevents curl/bot abuse)
- `Origin: null`: Falls back to `Referer` header

### Trusted Proxy Handling

The client IP is extracted from `REMOTE_ADDR` by default. The `X-Forwarded-For` header is only trusted when:

1. The `trustedProxies` config array is non-empty
2. The direct connection (`REMOTE_ADDR`) comes from an IP listed in `trustedProxies`

Without this, attackers can spoof their IP address to bypass rate limiting.

## Bot Detection

Two-tier bot detection prevents analytics pollution:

### Tier 1: User-Agent Pattern Matching

A combined regex of 50+ known bot patterns is compiled once at service instantiation and reused across all requests. Matches include: Googlebot, Bingbot, Slurp, DuckDuckBot, Baiduspider, curl, wget, Python-urllib, and many more.

### Tier 2: Header Heuristics

Requests missing the `Accept-Language` header are classified as bots. Legitimate browsers always send this header.

## Data Retention

Data is automatically purged by the `RetentionCleanupJob` (runs daily at 02:00 UTC):

| Data Type        | Default Retention  | Configurable Key            |
| ---------------- | ------------------ | --------------------------- |
| Raw page views   | 90 days            | `retention.raw_days`        |
| Raw events       | 90 days            | `retention.raw_days`        |
| Raw sessions     | 90 days            | `retention.raw_days`        |
| Hourly stats     | 48 hours           | `retention.hourly_hours`    |
| Daily stats      | 730 days (2 years) | `retention.aggregated_days` |
| Breakdown tables | 730 days (2 years) | `retention.aggregated_days` |

## CSV Export Security

The export endpoint includes CSV formula injection protection. Values starting with `=`, `+`, `-`, `@`, tab, or carriage return are prefixed with a tab character to prevent spreadsheet formula interpretation when opened in Excel or Google Sheets.

## Asset Security

The dashboard asset controller enforces:

- **Path traversal protection**: `..` and null byte rejection before filesystem access
- **Extension allowlist**: Only `css`, `js`, `svg`, `png`, `woff2`, `woff`, `ico`
- **Directory boundary check**: `realpath()` + `str_starts_with()` with `DIRECTORY_SEPARATOR`
- **Content-Type enforcement**: `X-Content-Type-Options: nosniff`

## CORS Policy

The `CollectionCorsMiddleware` sets CORS headers only for registered site domains:

```
Access-Control-Allow-Origin: https://example.com
Access-Control-Allow-Methods: POST
Access-Control-Allow-Headers: Content-Type
```

Preflight responses include `Access-Control-Max-Age: 86400` (24 hours).

## GDPR / ePrivacy Compliance

| Requirement                | How Analytics Complies                                |
| -------------------------- | ----------------------------------------------------- |
| No cookies without consent | No cookies used at all                                |
| Purpose limitation         | Data used only for aggregate analytics                |
| Data minimization          | Only page URL, referrer, screen width, UA collected   |
| Storage limitation         | Automatic retention cleanup (configurable)            |
| Right to erasure           | No personal data stored (HMAC is irreversible)        |
| DNT respect                | Configurable via `privacy.respect_dnt`                |
| Cross-site tracking        | Impossible by design (daily rotation + per-site keys) |

## Related Documentation

- [Getting Started](../user/getting-started.md) - Installation and configuration
- [Architecture](../developer/architecture.md) - Technical architecture details
- [API Reference](../developer/api-reference.md) - Endpoint documentation
