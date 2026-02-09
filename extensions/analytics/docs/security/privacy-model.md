# Analytics privacy and security model

Pulsar Analytics is designed from the ground up for privacy compliance. It uses no cookies, produces no cross-site tracking capabilities, and stores only pseudonymized visitor identifiers (not raw personal data).

## Privacy by design

### No cookies

The tracker script does not set, read, or require any cookies. No data is stored on the visitor's device (no cookies, no localStorage, no IndexedDB, no fingerprinting).

### Pseudonymized visitor identification

Visitor identification uses a keyed BLAKE2b hash that cannot be reversed to recover the original IP address or user agent:

```
visitor_id = keyed BLAKE2b(
    key: KDF(master_key, subkey_id=20, context="anal_vis"),
    data: ip + "|" + user_agent + "|" + utc_day_number
)
```

The raw IP address and user agent are never stored. Only the irreversible hash is persisted.

**GDPR classification:** Visitor IDs constitute pseudonymized data under GDPR Article 4(5), not anonymous data. An operator who possesses both the `PULSAR_MASTER_KEY` and access to web server logs (which contain raw IP addresses and user agents) could recompute the keyed BLAKE2b hash for known IP/UA combinations and correlate them to stored visitor IDs. This re-identification risk means GDPR obligations (lawful basis, data subject rights, retention limits) still apply. The daily rotation and per-site isolation reduce but do not eliminate this risk.

### Daily key rotation

Visitor hashes include the UTC day number (`timestamp / 86400`), which means:

- Visitor IDs change every day at UTC midnight
- It is impossible to track a visitor across days
- Historical data cannot be correlated to identify returning visitors
- The same visitor generates different IDs on different days

### No cross-site tracking

Each site has its own tracking ID and domain validation. There is no mechanism to correlate visitors across different sites, even if they share the same Pulsar installation.

## Cryptographic architecture

### Key derivation

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

### Key properties

| Property          | Value                                    |
| ----------------- | ---------------------------------------- |
| Master key size   | 256 bits                                 |
| Subkey ID         | 20                                       |
| KDF context       | `anal_vis` (8 bytes, per libsodium spec) |
| Hash algorithm    | BLAKE2b (via HMAC)                       |
| Visitor ID format | 64-character hex string                  |

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

| Requirement                | How Analytics Complies                                                                                           |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Lawful basis               | Legitimate interest (Art. 6(1)(f)) for pseudonymized web analytics; consent may be required per DPA guidance     |
| No cookies without consent | No cookies used at all                                                                                           |
| Purpose limitation         | Data used only for aggregate analytics                                                                           |
| Data minimization          | Only page URL, referrer, screen width, UA collected; raw IP/UA never stored                                      |
| Storage limitation         | Automatic retention cleanup (configurable)                                                                       |
| Pseudonymization           | Visitor IDs are pseudonymized per Art. 4(5); keyed BLAKE2b with daily rotation prevents casual re-identification |
| Right to erasure           | Pseudonymized data can be purged via retention cleanup; re-identification requires master key + server logs      |
| DNT respect                | Configurable via `privacy.respect_dnt`                                                                           |
| Cross-site tracking        | Impossible by design (daily rotation + per-site keys)                                                            |

## Related documentation

- [Getting Started](../user/getting-started.md) - Installation and configuration
- [Architecture](../developer/architecture.md) - Technical architecture details
- [API Reference](../developer/api-reference.md) - Endpoint documentation
