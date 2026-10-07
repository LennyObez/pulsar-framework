<?php

declare(strict_types=1);

/**
 * Pulsar Live reactive component configuration.
 *
 * @see \Pulsar\Live\LiveConfig
 */
return [
    // URL prefix for live component endpoints
    'endpoint_prefix' => '/_live',

    // Debounce delay for wire:model in milliseconds
    'debounce_ms' => 150,

    // Maximum request payload size in bytes (1 MiB)
    'max_payload_size' => 1_048_576,

    // Enable wire:poll directive
    'enable_polling' => true,

    // Default polling interval in milliseconds
    'default_poll_interval_ms' => 2000,

    // Enable DOM morphing (vs full replacement)
    'morph_dom' => true,
];
