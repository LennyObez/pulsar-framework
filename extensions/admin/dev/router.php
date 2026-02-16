<?php

declare(strict_types=1);

/**
 * Router script for the Admin panel development server (php -S).
 *
 * Usage: php -S host:port -t . extensions/admin/dev/router.php
 */

// Find project root by locating vendor/autoload.php
$dir = __DIR__;
while ($dir !== dirname($dir)) {
    if (file_exists($dir . '/vendor/autoload.php')) {
        break;
    }
    $dir = dirname($dir);
}

require $dir . '/vendor/autoload.php';

use Pulsar\Dev\DevServerBootstrap;
use Pulsar\Dev\DevServerConfig;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Config\CmsConfig;

DevServerBootstrap::run($dir, new DevServerConfig(
    extensionName: 'admin',
    assetPrefixes: [
        '/admin/assets/' => [
            'extensions/admin/frontend/styles',
            'extensions/admin/frontend/dist',
        ],
    ],
    containerBindings: [
        CmsConfig::class => new CmsConfig(
            editorialWorkflow: true,
            eventSourcing: true,
            atomicSnapshots: true,
            commerce: new CommerceConfig(),
        ),
    ],
    devIdentityRoles: ['admin', 'editor', 'moderator'],
    migrationDirs: ['extensions/*/src/Migration'],
));
