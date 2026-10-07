<?php

declare(strict_types=1);

/**
 * Observability config for the boot benchmark's fixture project.
 *
 * The log channel writes to php://memory: LoggingWiring builds the real handler
 * stack, but a benchmark that boots thousands of times writes no files.
 */

return [
    'logging' => [
        'default_channel' => 'memory',
        'level' => 'debug',
        'channels' => [
            'memory' => ['driver' => 'stream', 'stream' => 'php://memory'],
        ],
    ],
];
