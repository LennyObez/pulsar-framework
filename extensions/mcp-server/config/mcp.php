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
        'rate_limit_per_minute' => 60,
        'tool_rate_limits' => [],
        'max_concurrent_actions' => 1,
    ],
];
