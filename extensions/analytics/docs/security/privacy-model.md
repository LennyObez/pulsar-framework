# Analytics privacy and security model

Pulsar Analytics is designed from the ground up for privacy compliance. It uses no cookies, produces no cross-site tracking capabilities, and stores only pseudonymized visitor identifiers (not raw personal data).

## Privacy by design

### No cookies

The tracker script does not set, read, or require any cookies. No data is stored on the visitor's device (no cookies, no localStorage, no IndexedDB, no fingerprinting).

### Forward-secure visitor identification

Visitor identification uses a keyed BLAKE2b hash whose key is a **disposable per-day salt** — 32 fresh random bytes generated for each UTC day, stored durably, and destroyed after a short retention window:

```
salt_day   = 32 random bytes, generated once per UTC day, stored in
             analytics_visitor_salts, deleted by VisitorSaltPurgeJob after retention
visitor_id = keyed BLAKE2b(
                 key:  salt_day,
                 data: ip + "|" + user_agent + "|" + utc_day_number
             )
```

The raw IP address and user agent are never stored. Only the irreversible hash is persisted.

**Forward secrecy — the key property.** Within the retention window, an operator holding that day's salt _and_ server logs (raw IP/UA) could recompute the day's hashes, so live data is _pseudonymized_ under GDPR Art. 4(5). But once a day's salt is purged, that day's hashes can never be recomputed — from any inputs, even with the `PULSAR_MASTER_KEY` — so the historical data becomes genuinely _anonymous_ under GDPR Recital 26 and falls out of scope for data-subject rights. This is a deliberate improvement over the previous design, in which a stable master-key-derived key left every past hash brute-forceable forever from low-entropy IP/UA inputs.

### Per-day disposable salt

Each UTC day is keyed by its own random salt, which means:

- Visitor IDs change every day at UTC midnight (a new salt ⇒ unrelated hashes)
- It is impossible to track a visitor across days
- Once a past day's salt is purged, that day's IDs are irreversible — not merely unlinked, but unrecomputable
- The same visitor generates different, uncorrelatable IDs on different days

A short midnight grace window keeps the previous day's salt briefly so a session spanning midnight is stitched correctly; see _Salt lifecycle_ below.

### No cross-site tracking

Each site has its own tracking ID and domain validation. There is no mechanism to correlate visitors across different sites, even if they share the same Pulsar installation.

## Cryptographic architecture

### Visitor salts (tracking)

Visitor tracking is keyed by disposable per-day salts, **not** by a key derived from the master key:

- Each UTC day: `salt_day = random_bytes(32)`, hex-encoded, stored once via an atomic get-or-create in `analytics_visitor_salts`, so all requests that day observe a single salt and one visitor hashes to one ID.
- The salt is stored as plaintext random bytes on purpose — encrypting it with the master key would re-couple old data to that long-lived key and defeat forward secrecy, which comes from _deleting_ the salt, not from encrypting it.
- `VisitorSaltPurgeJob` (daily) deletes salts older than the retention window, which is what makes past days unrecomputable.

### Consent key (stable)

The one place that still derives a stable key from the master key is the **consent hash**: a visitor's recorded consent choice must remain re-identifiable across days to be honored, so it deliberately does not rotate.

```
master_key (PULSAR_MASTER_KEY env var)
    |
    +-- sodium_crypto_kdf_derive_from_key(subkey_id=20, context="anal_vis")
         |
         +-- consent_key  (stable; consent hash ONLY — not visitor tracking)
```

- **Master key**: 256-bit, stored in `PULSAR_MASTER_KEY` environment variable
- **Subkey derivation**: `sodium_crypto_kdf_derive_from_key()` with 8-byte context
- **HMAC**: BLAKE2b via `Pulsar\Security\Crypto\Hmac::computeHex()`

### Key properties

| Property            | Value                                                  |
| ------------------- | ------------------------------------------------------ |
| Visitor salt        | 32 random bytes/day, hex, in `analytics_visitor_salts` |
| Salt retention      | `privacy.visitor_salt_retention_days` (default 2)      |
| Consent subkey ID   | 20                                                     |
| Consent KDF context | `anal_vis` (8 bytes, per libsodium spec)               |
| Hash algorithm      | BLAKE2b (via HMAC)                                     |
| Visitor ID format   | 64-character hex string                                |

### Salt lifecycle and the forward-secrecy boundary

- A day's salt is created on the first tracked request of that UTC day and reused for the rest of the day.
- `VisitorSaltPurgeJob` runs daily and deletes salts older than `privacy.visitor_salt_retention_days` (default **2**, floored at 2). Two days — today and yesterday — are retained so the midnight session-grace lookup (which briefly consults yesterday's salt) is robust against purge timing and server timezone. The practical consequence is that yesterday's IDs stay recomputable, by someone holding the salt _and_ logs, for up to ~2 days rather than only the ~30-minute grace window. Lowering the setting toward 1 tightens that window at the cost of the robustness margin; the default keeps it wide for correctness.
- Once a salt row is deleted, the corresponding day's data is anonymous and unrecomputable.

### Migration boundary

Forward secrecy applies **from this version forward only.** Rows written before the disposable-salt change were hashed with the previous stable, master-key-derived key and remain recomputable (given the master key + server logs) until they age out through normal raw-data retention (`retention.raw_days`, default 90 days). Operators upgrading from an earlier version should treat pre-upgrade analytics rows as pseudonymized-but-recomputable until that window elapses, and may shorten `retention.raw_days` to expire the legacy rows sooner.

## Authentication and authorization

### Dashboard and API access

All dashboard and API endpoints (except collection and tracker) are protected by `AnalyticsAuthMiddleware`:

1. **Authentication check**: Requires a non-anonymous `IdentityInterface` on the request
2. **Authorization check**: Delegates to the framework's `GateInterface` (RBAC + ABAC) with the `analytics.view` permission

### Collection endpoint security

The public collection endpoint uses a layered defense:

| Layer                           | Protection                                           |
| ------------------------------- | ---------------------------------------------------- |
| `CollectionRateLimitMiddleware` | IP-based rate limiting (configurable per-minute cap) |
| `CollectionCorsMiddleware`      | Origin validation against registered site domains    |
| `BotFilterMiddleware`           | Bot detection via UA patterns and header heuristics  |
| Origin validation               | Origin/Referer header must match registered domain   |
| Trusted proxy validation        | X-Forwarded-For only trusted from configured proxies |

### Origin validation

Every collection request must include a valid `Origin` or `Referer` header matching the registered site domain:

- Exact domain match: `example.com`
- Subdomain match: `blog.example.com` matches `example.com`
- Missing both headers: **rejected** (prevents curl/bot abuse)
- `Origin: null`: Falls back to `Referer` header

### Trusted proxy handling

The client IP is extracted from `REMOTE_ADDR` by default. The `X-Forwarded-For` header is only trusted when:

1. The `trustedProxies` config array is non-empty
2. The direct connection (`REMOTE_ADDR`) comes from an IP listed in `trustedProxies`

Without this, attackers can spoof their IP address to bypass rate limiting.

## Bot detection

Two-tier bot detection prevents analytics pollution:

### Tier 1: user-agent pattern matching

A combined regex of 50+ known bot patterns is compiled once at service instantiation and reused across all requests. Matches include: Googlebot, Bingbot, Slurp, DuckDuckBot, Baiduspider, curl, wget, Python-urllib, and many more.

### Tier 2: header heuristics

Requests missing the `Accept-Language` header are classified as bots. Legitimate browsers always send this header.

## Data retention

Data is automatically purged by the `RetentionCleanupJob` (runs daily at 02:00 UTC):

| Data Type        | Default Retention  | Configurable Key            |
| ---------------- | ------------------ | --------------------------- |
| Raw page views   | 90 days            | `retention.raw_days`        |
| Raw events       | 90 days            | `retention.raw_days`        |
| Raw sessions     | 90 days            | `retention.raw_days`        |
| Hourly stats     | 48 hours           | `retention.hourly_hours`    |
| Daily stats      | 730 days (2 years) | `retention.aggregated_days` |
| Breakdown tables | 730 days (2 years) | `retention.aggregated_days` |

Visitor salts are purged separately by `VisitorSaltPurgeJob` (daily at 03:00 UTC):

| Data Type     | Default Retention     | Configurable Key                      |
| ------------- | --------------------- | ------------------------------------- |
| Visitor salts | 2 days (floored at 2) | `privacy.visitor_salt_retention_days` |

## CSV export security

The export endpoint includes CSV formula injection protection. Values starting with `=`, `+`, `-`, `@`, tab, or carriage return are prefixed with a tab character to prevent spreadsheet formula interpretation when opened in Excel or Google Sheets.

## Asset security

The dashboard asset controller enforces:

- **Path traversal protection**: `..` and null byte rejection before filesystem access
- **Extension allowlist**: Only `css`, `js`, `svg`, `png`, `woff2`, `woff`, `ico`
- **Directory boundary check**: `realpath()` + `str_starts_with()` with `DIRECTORY_SEPARATOR`
- **Content-Type enforcement**: `X-Content-Type-Options: nosniff`

## CORS policy

The `CollectionCorsMiddleware` sets CORS headers only for registered site domains:

```
Access-Control-Allow-Origin: https://example.com
Access-Control-Allow-Methods: POST
Access-Control-Allow-Headers: Content-Type
```

Preflight responses include `Access-Control-Max-Age: 86400` (24 hours).

## GDPR / ePrivacy compliance

| Requirement                | How Analytics Complies                                                                                                                                                  |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lawful basis               | Legitimate interest (Art. 6(1)(f)) for pseudonymized web analytics; consent may be required per DPA guidance                                                            |
| No cookies without consent | No cookies used at all                                                                                                                                                  |
| Purpose limitation         | Data used only for aggregate analytics                                                                                                                                  |
| Data minimization          | Only page URL, referrer, screen width, UA collected; raw IP/UA never stored                                                                                             |
| Storage limitation         | Automatic retention cleanup (configurable)                                                                                                                              |
| Pseudonymization           | Visitor IDs are pseudonymized per Art. 4(5) while the day's salt lives; once purged, the day's data is anonymous (Recital 26) — unrecomputable even with the master key |
| Right to erasure           | Live-window data is purgeable via retention cleanup; data whose salt is purged is already anonymous and outside erasure scope                                           |
| DNT respect                | On by default (`privacy.respect_dnt`), honored before any processing                                                                                                    |
| Cross-site tracking        | Impossible by design (per-day disposable salt + per-site isolation)                                                                                                     |

## Related documentation

- [Getting Started](../user/getting-started.md) - Installation and configuration
- [Architecture](../developer/architecture.md) - Technical architecture details
- [API Reference](../developer/api-reference.md) - Endpoint documentation
