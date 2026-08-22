<?php

declare(strict_types=1);

/**
 * Router script for the CMS development server (php -S).
 *
 * Usage: php -S host:port -t . extensions/cms/dev/router.php
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
    extensionName: 'cms',
    assetPrefixes: [
        '/admin/assets/' => [
            'extensions/admin/frontend/styles',
            'extensions/admin/frontend/dist',
        ],
        '/admin/cms/assets/' => [
            'extensions/cms/frontend/styles',
            'extensions/cms/frontend/dist',
        ],
        '/cms/assets/' => [
            'extensions/cms/frontend/styles',
            'extensions/cms/frontend/dist',
        ],
    ],
    templatePaths: [
        $dir . '/resources/views',
        $dir . '/extensions/cms/resources/views',
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
    migrationDirs: [dirname(__DIR__) . '/src/Migration'],
    criticalTables: [
        'cms_contents',
        'cms_redirects',
        'cms_editorial_reviews',
        'cms_site_settings',
        'cms_menus',
        'cms_media_assets',
    ],
    redirects: [
        '/admin' => '/admin/cms',
    ],
));
