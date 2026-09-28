<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'client_id' => 'default',
    'project_root' => '',
    'tools' => [
        'disabled_read_tools' => [],
        'allowed_actions' => [],
        'max_output_bytes' => 1_048_576,
        'action_timeout' => 120,
        'commands' => [
            'phpunit' => null,
            'composer' => null,
            'pnpm' => null,
        ],
    ],
    'security' => [
        'path_allowlist' => [],

        // NOT YET CONSUMED. Both are parsed into McpSecurityConfig and read by
        // nothing: MessageHandler calls whatever RateLimiterInterface the
        // container holds, whose limit and window come from `rate_limiting` in
        // config/security.php, and no limiter is bound at all when that section
        // is disabled. Changing either number here changes no limit anywhere.
        // Kept, rather than deleted, because the shape is the one the fix will
        // consume -- but an operator reading this file must not mistake them
        // for a control they have. See docs/mcp-server.md#rate-limiting.
        'rate_limit_per_minute' => 60,
        'tool_rate_limits' => [],

        'max_concurrent_actions' => 1,
    ],
];
