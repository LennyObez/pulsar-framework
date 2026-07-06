<?php

declare(strict_types=1);

/**
 * Anti-spam pipeline configuration.
 *
 * Controls which checks are enabled and their thresholds. The pipeline
 * runs registered checks in order against every submission context.
 */
return [
    // Honeypot: a hidden field that bots fill but humans don't
    'honeypot_enabled' => true,
    'honeypot_field_name' => 'website_url',

    // Duplicate detection: reject near-identical submissions
    'duplicate_detection_enabled' => true,
    'duplicate_window_seconds' => 300,
    'duplicate_similarity_threshold' => 85.0,

    // Link density: reject submissions with excessive URLs
    'link_density_enabled' => true,
    'max_link_density' => 0.3,

    // Content quality: reject empty, ALL-CAPS, or key-mashed content
    'content_quality_enabled' => true,
    'min_content_length' => 10,
    'max_uppercase_ratio' => 0.8,
    'max_repeated_char_ratio' => 0.5,

    // CAPTCHA: external verification (hCaptcha or Cloudflare Turnstile)
    'captcha_enabled' => false,
    'captcha_provider' => 'hcaptcha', // 'hcaptcha' or 'turnstile'
    'captcha_site_key' => env('CAPTCHA_SITE_KEY', ''),
    'captcha_secret_key' => env('CAPTCHA_SECRET_KEY', ''),

    // Account age gate: require minimum account age before posting
    'account_age_gate_enabled' => false,
    'min_account_age_seconds' => 300,

    // Reputation cooldown: per-tier rate limits between submissions
    'reputation_cooldown_enabled' => true,
    'cooldown_tiers' => [
        'new' => 60,
        'established' => 10,
        'moderator' => 0,
    ],

    // Short-circuit: stop on first failure instead of running all checks
    'short_circuit' => false,

    // AI-scraper / LLM-crawler defense: identify declared AI crawlers by
    // User-Agent and act per category. Opt-in; when enabled a global
    // middleware emits an X-Robots-Tag: noai, noimageai signal and applies
    // the configured action. Per-crawler 'overrides' and 'custom_crawlers'
    // let you extend the built-in list without a release.
    'ai_crawlers' => [
        'enabled' => false,
        'training_action' => 'block',   // 'allow' | 'block' | 'rate_limit'
        'assistant_action' => 'allow',
        'search_action' => 'allow',
        'overrides' => [],              // ['GPTBot' => 'rate_limit', ...]
        'custom_crawlers' => [],        // ['MyBot' => 'training', ...]
        'send_tdm_reservation' => true, // emit the TDM reservation header
        'rate_limit_max_requests' => 60,
        'rate_limit_window_seconds' => 60,
    ],

    // AI-crawler identity verification: defeat User-Agent spoofing by checking
    // that a declared crawler connects from its operator's published IP ranges
    // (issuers publish these, e.g. OpenAI's gptbot.json) and/or forward-confirmed
    // reverse DNS. A crawler with verification data that matches nothing is
    // treated as an impersonator and blocked, regardless of its action.
    'ai_crawler_verification' => [
        'enabled' => false,
        'reverse_dns' => false, // also accept forward-confirmed reverse DNS (blocking lookups, cached)
        'ranges' => [           // UA token => published IP/CIDR ranges
            // 'GPTBot' => ['203.0.113.0/24', '198.51.100.0/24'],
        ],
        'domains' => [          // UA token => expected reverse-DNS host suffixes
            // 'GPTBot' => ['openai.com'],
        ],
        'cache_ttl_seconds' => 3600,
    ],

    // Adaptive, risk-based challenge escalation: composes risk signals
    // (bot heuristics, JA4, ...) into a single score and escalates —
    // allow below challenge_threshold, challenge between, block above
    // block_threshold. Opt-in; attaches the assessment to the request so
    // downstream form/challenge layers can react.
    'adaptive_risk' => [
        'enabled' => false,
        'challenge_threshold' => 0.5,
        'block_threshold' => 0.9,
    ],

    // JA4/JA4+ TLS-fingerprint risk signal. The application cannot compute a
    // JA4 fingerprint (no access to the raw TLS ClientHello), so it must be
    // computed at the TLS-terminating edge and forwarded in 'header_name'.
    // Honoured only for requests arriving through a trusted proxy
    // (trusted_proxies_only — requires deploy.trusted_proxies), so a direct
    // client cannot spoof the header. Feeds the adaptive_risk engine above.
    'ja4' => [
        'enabled' => false,
        'header_name' => 'X-JA4',
        'trusted_proxies_only' => true,
        'known_bad_fingerprints' => [], // operator-supplied exact JA4 strings
        'known_bad_prefixes' => [],     // JA4 prefixes (e.g. the JA4_a component) flagging a family
        'known_good_fingerprints' => [], // exact allowlist; overrides the prefix denylist
        'match_score' => 0.9,           // exact denylist hit
        'partial_match_score' => 0.6,   // prefix/family hit (lower confidence)
    ],

    // Request-velocity risk signal: a client exceeding `threshold` requests per
    // `window_seconds` contributes risk to the adaptive engine, scaling to
    // `max_score`. Per-origin local reputation; requires the cache.
    'velocity' => [
        'enabled' => false,
        'threshold' => 120,
        'window_seconds' => 60,
        'max_score' => 0.7,
    ],

    // Datacenter/hosting-IP risk signal: a client whose (trusted-proxy-resolved)
    // IP falls in operator-supplied datacenter CIDR ranges contributes `score`.
    // No bundled ASN database — export ranges from your provider/threat feed.
    'datacenter' => [
        'enabled' => false,
        'ranges' => [], // e.g. ['198.51.100.0/24', '2001:db8::/32']
        'score' => 0.5,
    ],

    // Private Access Tokens (Privacy Pass, RFC 9577/9578). Pulsar acts as the
    // Origin: it advertises a token challenge on denied responses and accepts a
    // redeemed token as proof of a legitimate client — a valid token bypasses
    // the adaptive_risk challenge above. Pulsar does not issue tokens; configure
    // the issuer name and its public key (base64url SPKI using id-RSASSA-PSS)
    // out of band. Requires adaptive_risk enabled and the gmp PHP extension.
    'privacy_pass' => [
        'enabled' => false,
        'issuer_name' => '',  // e.g. 'demo-issuer.example'
        'origin_info' => '',  // your origin host (comma-separated), or '' for any
        'token_key' => '',    // base64url SPKI of the (primary) issuer public key
        'token_keys' => [],   // additional keys for seamless rotation (old + new both verify)
        'directory_url' => '', // RFC 9576 issuer directory; refresh with `privacy-pass:keys:refresh`
        'single_use' => true, // reject a redeemed token's nonce on replay (needs the cache)
        'single_use_ttl_seconds' => 86400,
    ],
];
