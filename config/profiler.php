<?php

declare(strict_types=1);

/**
 * Per-request performance profiler.
 *
 * Opt-in and intended for development/staging — it adds per-request overhead and
 * exposes timing detail. When enabled, each response carries a Server-Timing
 * header (total, DB, cache) and the profiler keeps a ring buffer of recent
 * request profiles. Database query timings and cache hits/misses are recorded
 * automatically when the database and cache are wired.
 */
return [
    'enabled' => false,
    'max_entries' => 512,  // max timeline entries kept per request
    'max_profiles' => 50,  // recent request profiles retained in memory
];
