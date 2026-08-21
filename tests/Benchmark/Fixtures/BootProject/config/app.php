<?php

declare(strict_types=1);

/**
 * Config for the boot benchmark's fixture project.
 *
 * Deliberately minimal and production-shaped: `debug` is false so the boot takes
 * the same branches a deployed application takes, and no value here is tuned to
 * make the boot cheaper than a real one.
 */

return [
    'name' => 'PulsarBootBenchmark',
    'env' => 'local',
    'debug' => false,
    'timezone' => 'UTC',
    'locale' => 'en',
];
