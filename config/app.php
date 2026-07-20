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
    | Enabled Extensions
    |--------------------------------------------------------------------------
    |
    | When set, only extensions whose names appear in this list will be loaded.
    | If this key is absent or null, all discovered extensions are loaded
    | (backward compatible default). Extension names use the format defined
    | in pulsar.json manifests (e.g., 'pulsar/cms', 'pulsar/forum').
    |
    | Example:
    |   'extensions' => ['enabled' => ['pulsar/cms', 'pulsar/analytics']],
    |
    */
    // 'extensions' => ['enabled' => null],

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
