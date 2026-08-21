<?php

declare(strict_types=1);

/**
 * Cache config for the boot benchmark's fixture project.
 *
 * Enabled, so CacheWiring binds TaggedCacheInterface and the wirings that depend
 * on it (anti-spam, WAF, threat detection) build their full service graph rather
 * than the degraded one they fall back to when the binding is absent.
 */

return [
    'enabled' => true,
];
