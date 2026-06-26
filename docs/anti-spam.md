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

### Localization

The widget's user-facing strings — the `<noscript>` fallback for clients with JavaScript disabled, and the status the widget announces into its `role="status"` live region for screen readers — are resolved from the **`shield`** translation domain, falling back to built-in English. No host configuration is required; provide a `shield` catalog only for the locales you want to translate.

| Key         | Default (English)                                           | Where it appears                    |
| ----------- | ----------------------------------------------------------- | ----------------------------------- |
| `noscript`  | This form requires JavaScript to complete a security check. | `<noscript>` fallback (no-JS users) |
| `verifying` | Verifying your request…                                     | live-region status while solving    |
| `complete`  | Security check complete.                                    | live-region status once solved      |
| `error`     | Security verification failed. Please reload the page.       | live-region status on failure       |

Example `lang/fr/shield.php`:

```php
return [
    'noscript' => 'Ce formulaire nécessite JavaScript pour effectuer une vérification de sécurité.',
    'verifying' => 'Vérification de votre requête…',
    'complete' => 'Vérification de sécurité terminée.',
    'error' => 'Échec de la vérification de sécurité. Veuillez recharger la page.',
];
```

The status strings are rendered into `data-pmc-msg-*` attributes on the widget element and read by the worker, so the same translation feeds both server and client with no second hardcoded copy in the JS bundle. A key absent from the catalog uses the English default (it is never emitted as a raw key, even in i18n strict mode).

### Verifying a submission

When the anti-spam pipeline runs (e.g. the CMS comment or Forum post middleware), populate `AntiSpamContext::$captchaToken` from the `managed_challenge_field_name` field. The pipeline's CAPTCHA check (`ManagedChallengeVerifier`, named `captcha`) verifies it. No code change is needed beyond selecting `captcha_provider: 'managed'`.

### Served assets

When enabled, three same-origin routes are registered (cached with an ETag, refreshed on framework upgrade):

| Route                                            | Purpose                                  |
| ------------------------------------------------ | ---------------------------------------- |
| `/_pulsar/anti-spam/managed-challenge.js`        | Widget bootstrap (classic script)        |
| `/_pulsar/anti-spam/managed-challenge.worker.js` | Proof-of-work Web Worker (ES module)     |
| `/_pulsar/anti-spam/managed-challenge.pow.js`    | Shared SHA-256 / proof-of-work core      |
| `/_pulsar/anti-spam/managed-challenge/refresh`   | Mints a fresh challenge (silent refresh) |

The Web Worker is an ES-module worker, so the widget requires a browser supporting module workers. Under a strict CSP, allow `script-src 'self'`, `worker-src 'self'`, and `connect-src 'self'` (the refresh fetch).

### Silent refresh

A short TTL gives a small replay window but would reject a slow human who fills a form for minutes. The widget closes that gap without widening the window: after solving, it schedules a silent re-mint at ~80% of the TTL (and re-mints immediately on the form's first focus if the current token is already stale). It `fetch()`es a fresh challenge from the refresh endpoint, solves it in the worker, and overwrites the hidden field — never blocking the user; if a refresh fails, the last solved token is kept.

The refresh endpoint is stateless, same-origin, carries no PII, and needs no auth (it only issues PUBLIC, unsolved challenges); a best-effort per-IP cache counter caps abuse. This lets `managed_challenge_ttl_seconds` stay tight (default 300) for a small replay window while the widget keeps the token fresh. **The short TTL is only safe with refresh enabled:** clients with JavaScript disabled cannot refresh, so cover them with the [time-trap](#time-trap-no-javascript-form-fill-timing) and, if a long-lived no-JS form is required, a longer configured fallback TTL.

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

## Behavioural Signals (self-hosted, score-only)

The behavioural-signals check narrows the gap to a third-party CAPTCHA's behavioural layer with **no third party**. A same-origin collector script measures how the form was used and the server grades those signals into a spam score. It is **score-only** — it never hard-rejects — because behavioural heuristics are probabilistic and a false positive must never block a real user; the score combines with the other pipeline checks.

### Signals collected

The collector (`@shield`, or `BehaviorCollectorRenderer`) records only **non-identifying, aggregate** measurements into the form's own hidden field: interaction-present, total fill duration, normalised pointer-movement entropy, the `navigator.webdriver` automation flag, the paste-vs-type ratio, and a keydown count. The default `HeuristicScorer` (a transparent linear model with configurable weights) grades them; an application can bind its own `BehaviorScorerInterface` — e.g. a trained model — without touching the framework. An opt-in `BehaviorFeatureSink` (default `NullBehaviorFeatureSink`, no-op) can capture `{feature_vector, score}` pairs for offline training.

### Configuration

```php
// config/anti-spam.php
return [
    'behavior_enabled' => true,            // opt-in; default false
    'behavior_field_name' => 'pulsar-bx',  // hidden field the collector fills
    'behavior_weights' => [                // HeuristicScorer weight overrides
        'webdriver' => 40,
        'no_interaction' => 30,
        'too_fast' => 20,
        'no_pointer_entropy' => 10,
        'high_paste' => 15,
        'min_human_fill_ms' => 800,
        'high_paste_ratio' => 0.9,
    ],
];
```

### Privacy (GDPR)

By design the collector captures **no personal data**: no field values, no typed or pasted text, no mouse coordinates, no IP, no cookie, and **no persistent or cross-site identifier**. Only the aggregate counters above are serialised, into the form's own hidden field, and nothing is sent anywhere else. With JavaScript disabled the field stays empty and the server scores it as "no signal" (zero) — never penalising the user. This keeps the feature GDPR-clean by construction and free of the consent burden a third-party behavioural CAPTCHA carries.

### Honest limitation

A self-hosted engine sees only **your own** origin. It has **no global cross-site reputation** — the network effect a large third-party CAPTCHA derives from observing a device across millions of sites. That signal is deliberately out of scope: it is irreconcilable with self-hosting and zero data sharing. Deploy behavioural signals as one score-only layer among honeypot, rate limiting, content checks, the managed challenge, and the time-trap — not as a sole defence.

## AI-Scraper Defense (declared LLM crawlers)

Declared AI crawlers — training scrapers, assistant fetchers, and AI search indexers — identify themselves by `User-Agent`. The AI-scraper defense matches those tokens against a built-in list (extensible at runtime) and acts per **category**, so you can block training-corpus harvesting while still allowing the assistant and search fetchers that drive referral traffic. It is opt-in; when enabled, a global middleware runs before routing.

### How it works

`AiCrawlerDetector` classifies the request's `User-Agent` into a category (`training`, `assistant`, `search`) and `AiCrawlerMiddleware` applies the configured `AiCrawlerAction`:

- **block** → `403` with `X-Robots-Tag: noai, noimageai`.
- **rate_limit** → a sliding-window limiter (`rate_limit_max_requests` / `rate_limit_window_seconds`); over the limit returns `429`.
- **allow** → passes through, but still stamps `X-Robots-Tag: noai, noimageai` so a compliant crawler honours the opt-out.

When `send_tdm_reservation` is on, responses also carry the TDM (Text & Data Mining) reservation header — the machine-readable opt-out from the EU DSM Directive — so reservation is asserted even for crawlers you allow to fetch.

### Configuration

```php
// config/anti-spam.php
'ai_crawlers' => [
    'enabled' => false,             // opt-in
    'training_action' => 'block',   // 'allow' | 'block' | 'rate_limit'
    'assistant_action' => 'allow',
    'search_action' => 'allow',
    'overrides' => [],              // ['GPTBot' => 'rate_limit', ...]
    'custom_crawlers' => [],        // ['MyBot' => 'training', ...]  (UA token => category)
    'send_tdm_reservation' => true,
    'rate_limit_max_requests' => 60,
    'rate_limit_window_seconds' => 60,
],
```

`overrides` change the action for a specific named crawler; `custom_crawlers` add UA tokens the built-in list doesn't know yet, mapped to a category — both let you adapt without waiting for a release.

### Honest limitation

This layer recognises crawlers that **declare themselves**. A scraper that forges a browser `User-Agent` is not caught here — that is the job of the other layers (rate limiting, behavioural signals, the adaptive engine, and the JA4 signal below). Treat AI-scraper defense as the polite-but-enforced front door for honest bots, not as anti-evasion.

## Adaptive Risk Engine (risk-based escalation)

Rather than applying one fixed challenge to everyone, the adaptive engine composes several **risk signals** into a single score and escalates proportionally: allow low-risk traffic untouched, challenge the ambiguous middle, and block the clearly malicious. This keeps friction off legitimate users while raising cost on bots.

### How it works

Each registered `RiskSignalProviderInterface` returns a score in `[0.0, 1.0]`. `AdaptiveRiskEngine` combines them **probabilistically** — `1 − ∏(1 − sᵢ)` — so independent weak signals accumulate without ever exceeding `1.0`, then maps the result to a decision:

- below `challenge_threshold` → **allow**
- between the thresholds → **challenge**
- at/above `block_threshold` → **block**

A `RiskBypassProviderInterface` can short-circuit to allow (e.g. an authenticated, trusted principal) before any scoring runs. `AdaptiveChallengeMiddleware` blocks `block`-rated requests with `403`; otherwise it attaches the `RiskAssessment` to the request (`RiskAssessment::REQUEST_ATTRIBUTE`) so the downstream form/challenge layer can decide how to present the challenge. The default signal provider adapts the transparent `BotDetector` (header/`User-Agent` heuristics, scored 0–100) into a normalised signal.

### JA4/JA4+ TLS fingerprint

A JA4 fingerprint summarises the TLS ClientHello — cipher suites, extensions, ALPN — into a stable string that is far harder to forge than a `User-Agent`, because it reflects the actual TLS stack. The application layer **cannot compute it**: by the time a request reaches PHP, the TLS handshake is over and the raw ClientHello is gone. So JA4 must be computed at the TLS-terminating edge / reverse proxy and forwarded in a header (default `X-JA4`).

Because a client connecting directly could simply _send_ that header, it is honoured **only for requests arriving through a trusted proxy** (`trusted_proxies_only`, on by default — requires `deploy.trusted_proxies`). `Ja4SignalProvider` checks `REMOTE_ADDR` against the trusted set via `TrustedProxy`; an untrusted source's header is ignored and contributes zero risk. It is a **denylist** signal — an absent or unknown fingerprint adds no risk; only an operator-supplied known-bad fingerprint scores `match_score`, feeding the engine above. Known-bad lists are operator-supplied (e.g. from a threat feed) rather than baked in, since JA4 values shift with TLS-stack versions and a hardcoded list would rot.

### Configuration

```php
// config/anti-spam.php
'adaptive_risk' => [
    'enabled' => false,           // opt-in
    'challenge_threshold' => 0.5,
    'block_threshold' => 0.9,
],
'ja4' => [
    'enabled' => false,           // opt-in; requires a TLS-terminating edge
    'header_name' => 'X-JA4',
    'trusted_proxies_only' => true,
    'known_bad_fingerprints' => [], // exact JA4 strings treated as malicious
    'match_score' => 0.9,
],
```

### Honest limitation

The engine is only as good as its signals. Out of the box it scores header/`User-Agent` heuristics plus (when configured) an edge-supplied JA4 denylist; it has no global cross-site reputation. JA4 in particular depends on an edge that computes the fingerprint and on you maintaining the known-bad list — without a trusted proxy emitting it, the JA4 signal is correctly inert. Compose it with the other pipeline layers rather than relying on the score alone.
