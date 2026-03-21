<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FullSiteEditor;

use Pulsar\Api\Api;

/**
 * Persistence for global styles configuration.
 *
 * @psalm-api Public binding contract; implemented by FullSiteEditor service
 *            and consumed by admin controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface GlobalStylesRepositoryInterface
{
    public function load(string $themeId = ''): GlobalStylesConfig;

    public function save(GlobalStylesConfig $config, string $themeId = ''): void;
}
