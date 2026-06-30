# Anti-Spam

Pulsar ships a composable anti-spam pipeline (`Pulsar\Security\AntiSpam`) of independent checks — honeypot, duplicate detection, link density, content quality, proof-of-work, account-age gate, reputation cooldown, CAPTCHA, and the no-JavaScript time-trap — each implementing `AntiSpamCheckInterface` and run by `AntiSpamPipeline`. Checks are enabled and tuned through `config/anti-spam.php`.

This document focuses on the **Managed Challenge** — the self-hosted CAPTCHA provider.

## Managed Challenge (self-hosted CAPTCHA)

A privacy-preserving, invisible proof-of-work CAPTCHA that needs **no external service**. Where Cloudflare Turnstile or hCaptcha send a token to a third-party `siteverify` endpoint, the Managed Challenge issues, solves, and verifies entirely on your own origin:

- **No third party.** No request leaves your server; no Cloudflare/hCaptcha account, site key, or secret key.
- **No tracking.** No cookies, no fingerprinting, no behavioural telemetry — GDPR-clean by construction.
- **CSP `script-src 'self'` clean.** The widget and its Web Worker are served from your own origin; no inline scripts, no CDN, no `blob:` worker.
- **Invisible.** The proof-of-work runs in a Web Worker on page load; legitimate users never see a puzzle.

### How it works

1. The `@shield` directive (or `ManagedChallengeRenderer`) mints a fresh challenge `{id, difficultyBits, issuedAt}`, signs it with `sodium_crypto_auth` (HMAC-SHA-512/256) keyed by a derived master sub-key, and embeds it in the form alongside a same-origin `<script>`.
2. A Web Worker searches for a `solution` such that `SHA-256(id + '.' + solution)` has at least `difficultyBits` leading zero bits, then writes `challengeToken.solution` into a hidden field.
3. On submission, `ManagedChallengeVerifier` validates the token: **signature** (constant-time) → **freshness** (TTL window) → **proof-of-work** → **single-use** (a replay cache keyed on the challenge id). The difficulty and issue time are signed in, so a client cannot weaken the puzzle or replay an expired or already-used token.

### Configuration

```php
// config/anti-spam.php
return [
    'captcha_enabled' => true,
    'captcha_provider' => 'managed', // self-hosted; no keys required

    // Proof-of-work difficulty in leading zero bits. ~16 solves in well under a
    // second on a typical device; raise for stronger abuse resistance at the
    // cost of more client CPU. Signed into each challenge.
    'managed_challenge_bits' => 16,

    // How long an issued challenge remains valid.
    'managed_challenge_ttl_seconds' => 300,

    // Form field the widget writes its solved token into. Your submission
    // handler must read this field into AntiSpamContext::$captchaToken.
    'managed_challenge_field_name' => 'pulsar-challenge-response',
];
```

Requires `PULSAR_MASTER_KEY` (used to derive the signing sub-key). A `TaggedCacheInterface` binding is strongly recommended — without it, single-use replay protection degrades to the TTL window only (logged at boot). The provider is disabled (logged, not fatal) if no master key is configured.

### Rendering the widget

Add `@shield` inside the form. It renders the hidden field and the same-origin widget script, stamping the request CSP nonce on the script tag:

```html
<form method="post" action="/comments">
  @csrf @shield
  <textarea name="body"></textarea>
  <button type="submit">Post</button>
</form>
```

Renders nothing when the `managed` provider is not configured, so templates degrade quietly.

In a controller you can inject `ManagedChallengeRenderer` and call `render(?string $cspNonce)` directly.

### Verifying a submission

When the anti-spam pipeline runs (e.g. the CMS comment or Forum post middleware), populate `AntiSpamContext::$captchaToken` from the `managed_challenge_field_name` field. The pipeline's CAPTCHA check (`ManagedChallengeVerifier`, named `captcha`) verifies it. No code change is needed beyond selecting `captcha_provider: 'managed'`.

### Served assets

When enabled, three same-origin routes are registered (cached with an ETag, refreshed on framework upgrade):

| Route                                            | Purpose                              |
| ------------------------------------------------ | ------------------------------------ |
| `/_pulsar/anti-spam/managed-challenge.js`        | Widget bootstrap (classic script)    |
| `/_pulsar/anti-spam/managed-challenge.worker.js` | Proof-of-work Web Worker (ES module) |
| `/_pulsar/anti-spam/managed-challenge.pow.js`    | Shared SHA-256 / proof-of-work core  |

The Web Worker is an ES-module worker, so the widget requires a browser supporting module workers. Under a strict CSP, allow `script-src 'self'` and `worker-src 'self'`.

### Security properties

- **Tamper-proof difficulty.** `difficultyBits` and `issuedAt` are inside the signed payload; a client cannot lower the difficulty or extend the lifetime.
- **Single-use.** Each solved token is consumed once (replay cache on the challenge id) for its remaining lifetime.
- **No IP binding.** Deliberately not bound to client IP (which breaks behind proxies, NAT, and mobile networks), matching Turnstile's model.
- **Constant-time verification.** Signature checks use `sodium_crypto_auth_verify`.
- **Domain-separated key.** Signing uses master sub-key id 16 (`antispam` context), isolated from CSRF, sessions, audit, and the vault.

### Trade-offs vs. Turnstile

The Managed Challenge trades Turnstile's server-side ML/behavioural risk scoring for full self-hosting and zero data sharing. It is a strong, invisible bot-cost gate, best deployed as one layer of the pipeline (alongside honeypot, rate limiting, and content checks) rather than as a sole defence. Difficulty is fixed per configuration; adaptive difficulty under load is a planned enhancement.

## Time-Trap (no-JavaScript form-fill timing)

The time-trap rejects submissions whose form-fill timing is implausible for a human: posted faster than a person could read and complete the form (a bot submitting on page load), or after a stale delay (a long-cached or replayed page). Crucially it needs **no JavaScript** — the render stamp is a server-rendered hidden field — so it closes the form-timing gap the (JS-only) Managed Challenge leaves open for scripting-disabled clients.

### How it works

1. The `@timetrap` directive (or `@shield`, which emits it alongside the Managed Challenge) mints a stamp `{issuedAt, formId}`, signs it with `sodium_crypto_auth` (HMAC-SHA-512/256) keyed by a derived master sub-key, and embeds it as a single hidden `<input>` — no script, no inline code.
2. On submission, `TimeTrapCheck` reads the field, verifies the signature in constant time, confirms the stamp was minted for this form (`formId`), and computes the fill duration. A duration below `time_trap_min_seconds` or above `time_trap_max_seconds` is flagged (advisory score 30, like the honeypot); a small negative clock skew (−5s) is tolerated.

### Configuration

```php
// config/anti-spam.php
return [
    // Opt-in: defaults preserve existing behaviour (disabled).
    'time_trap_enabled' => true,
    // Minimum plausible human fill time; faster ⇒ flagged.
    'time_trap_min_seconds' => 3,
    // Maximum stamp age before the page is treated as stale.
    'time_trap_max_seconds' => 3600,
    // Hidden field name carrying the signed render timestamp.
    'time_trap_field_name' => 'pulsar-form-ts',
];
```

The check requires a configured `PULSAR_MASTER_KEY`; without one it disables itself (logged) rather than failing boot.

### Rendering and verifying

Add `@timetrap('your-form-id')` (or just `@shield`) inside the form. To bind a stamp to one form so it cannot be replayed against another, pass the same identifier to the renderer and set `AntiSpamContext::$formId` to it at submission. With no `formId` the stamp still enforces the fill-time window; only cross-form replay protection is waived.

### Security properties

- **Tamper-proof timing.** `issuedAt` and `formId` are inside the signed payload; a client cannot backdate the stamp to beat the minimum, nor extend it past the maximum.
- **Cross-form replay resistance.** The signed `formId` binds a stamp to one form/route; constant-time compared against the submission's form.
- **Constant-time verification.** Signature checks use `sodium_crypto_auth_verify`.
- **Domain-separated key.** Signing uses master sub-key id 17 (`antispam` context), distinct from the Managed Challenge's id 16 so the two features never share key material.
- **No JavaScript, CSP-clean.** The field is plain server-rendered HTML — no inline script, no external request.

### Trade-offs

The time-trap is a cheap, robust complement to the Managed Challenge, not a replacement: timing alone is a weak signal in isolation (a patient bot can wait), so deploy it as one layer of the pipeline. Because the stamp is a timestamp rather than a single-use nonce, the same form's stamp may be submitted more than once within the window — replay _of a different form_ is what the `formId` binding prevents.
