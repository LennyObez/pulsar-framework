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
];
