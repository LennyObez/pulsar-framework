<?php

declare(strict_types=1);

/**
 * View & Template Engine Configuration
 *
 * Settings for the Pulsar template engine, including template search paths,
 * compiled cache location, escaping defaults, theme selection, and sandbox
 * limits for untrusted templates.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Template Paths
    |--------------------------------------------------------------------------
    | Ordered list of directories where the engine searches for templates.
    | Templates are resolved in order; the first match wins.
    */
    'template_paths' => [
        'resources/views',
        'extensions/cms/resources/views',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Path
    |--------------------------------------------------------------------------
    | Directory for storing compiled template cache files. Must be writable.
    */
    'cache_path' => 'storage/cache/views',

    /*
    |--------------------------------------------------------------------------
    | Auto Escape
    |--------------------------------------------------------------------------
    | When true (default), all {{ $var }} output is HTML-escaped automatically.
    | Use {!! $var !!} for raw (trusted) output when escaping is not desired.
    */
    'auto_escape' => true,

    /*
    |--------------------------------------------------------------------------
    | Active Theme
    |--------------------------------------------------------------------------
    | The active theme name. Maps to resources/themes/{name}.css.
    | The 'default' theme ships with the framework and is always available.
    */
    'active_theme' => 'default',

    /*
    |--------------------------------------------------------------------------
    | @php Directive
    |--------------------------------------------------------------------------
    | Whether @php blocks are permitted at compile time. Disabled by default
    | in production for security. When enabled, each usage emits a compliance
    | audit event.
    */
    'php_directive_allowed' => true,

    /*
    |--------------------------------------------------------------------------
    | Sandbox: Step Limit
    |--------------------------------------------------------------------------
    | Maximum number of AST node evaluations for untrusted templates.
    | Prevents runaway template execution.
    */
    'sandbox_step_limit' => 10_000,

    /*
    |--------------------------------------------------------------------------
    | Sandbox: Loop Limit
    |--------------------------------------------------------------------------
    | Maximum iterations per loop construct in untrusted templates.
    */
    'sandbox_loop_limit' => 1_000,

    /*
    |--------------------------------------------------------------------------
    | Sandbox: Output Size Limit
    |--------------------------------------------------------------------------
    | Maximum rendered output size in bytes for untrusted templates.
    | Default: 1 MB (1,048,576 bytes).
    */
    'sandbox_output_size_limit' => 1_048_576,

    /*
    |--------------------------------------------------------------------------
    | Sandbox: Wall Clock Check Interval
    |--------------------------------------------------------------------------
    | Number of AST steps between wall-clock time checks in untrusted mode.
    | Lower values increase safety but add overhead.
    */
    'sandbox_wall_clock_check_interval' => 500,
];
