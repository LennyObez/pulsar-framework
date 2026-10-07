<?php

declare(strict_types=1);

/**
 * Security config for the boot benchmark's fixture project.
 *
 * CSRF and rate limiting are off because both bind per-request middleware whose
 * cost belongs to the request benchmarks, not to boot; every service SecurityWiring
 * builds is still constructed.
 */

return [
    'session' => [],
    'csrf' => ['enabled' => false],
    'headers' => [],
    'rate_limiting' => ['enabled' => false],
];
