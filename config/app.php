<?php

declare(strict_types=1);

/**
 * Application Configuration
 *
 * Core application settings for Pulsar.
 * Environment-specific values should use getenv() or a dedicated secrets loader.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => 'Pulsar',

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    | Supported: "local", "staging", "production"
    */
    'env' => 'local',

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    | Must be false in production.
    */
    'debug' => false,

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale
    |--------------------------------------------------------------------------
    */
    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Front-End Signature
    |--------------------------------------------------------------------------
    | Opt-in "Made with Pulsar" signal, rendered as <meta> tags in the <head>
    | of framework-rendered pages and exposed to every template as the
    | $pulsarSignature view variable.
    |
    | This is a FRONT-END signal, never an HTTP header. Pulsar deliberately does
    | NOT emit an X-Powered-By / Server header: advertising the framework or its
    | version to every client — including attackers — is a fingerprinting leak
    | (OWASP ASVS V14.4.1), and those headers are stripped at the emitter. A
    | <meta name="generator"> carries no version and is disabled by default, so
    | nothing is disclosed unless you opt in here.
    |
    |   generator => emit <meta name="generator" content="Pulsar">
    |   author    => emit <meta name="author" content="..."> (site owner; blank = omit)
    */
    'signature' => [
        'generator' => false,
        'author' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions — enable posture
    |--------------------------------------------------------------------------
    |
    | Bundled APPLICATION/PRODUCT extensions (forum, cms, payments, tickets,
    | messaging, booking, analytics, feedback, devices, subscriptions, releases,
    | ai-governance, health-status) declare `"kind": "product"` in their
    | pulsar.json and are present on disk but OFF by default — a regulated app
    | should not inherit a forum or a beta-signup PII collector it never asked
    | for. Framework INFRASTRUCTURE and security/compliance extensions, and any
    | extensions you author yourself, load without ceremony. The infra/product
    | split is the single source of truth in each manifest's `kind`, drift-guarded
    | by ExtensionSandboxDriftTest.
    |
    | Turn specific products ON (additive — leaves the rest of the default
    | posture intact):
    |   'extensions' => ['enabled_products' => ['pulsar/forum', 'pulsar/cms']],
    |
    | Or take full manual control with an EXCLUSIVE allowlist — when set, ONLY
    | the named extensions load (products, infrastructure, and your own alike):
    |   'extensions' => ['enabled' => ['pulsar/auth', 'pulsar/orm', 'pulsar/forum']],
    |
    */
    'extensions' => [],

    /*
    |--------------------------------------------------------------------------
    | Strict configuration keys
    |--------------------------------------------------------------------------
    |
    | When an unrecognized key is found in a config file (a typo like
    | `handler` written as `driver`), Pulsar warns at boot by default. Set
    | `config.strict_keys` to true — or PULSAR_CONFIG_STRICT=true, ideally
    | scoped to production — to fail the boot instead, so a misconfiguration
    | can never silently reach a running system. See ADR-0036.
    |
    */
    // 'config' => ['strict_keys' => false],
];
