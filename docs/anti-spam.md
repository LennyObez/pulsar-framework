# Anti-Spam

Pulsar ships a composable anti-spam pipeline (`Pulsar\Security\AntiSpam`) of independent checks — honeypot, duplicate detection, link density, content quality, proof-of-work, account-age gate, reputation cooldown, and CAPTCHA — each implementing `AntiSpamCheckInterface` and run by `AntiSpamPipeline`. Checks are enabled and tuned through `config/anti-spam.php`.

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
