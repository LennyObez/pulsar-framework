<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Publishing;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Content;

/**
 * Orchestrates publishing/unpublishing content across channels.
 *
 * @psalm-api Public binding contract; implemented by PublishingOrchestrator
 *            and consumed by the publishing state machine.
 * @api
 */
#[Api(since: '1.0.0')]
interface PublishingOrchestratorInterface
{
    /**
     * Publish content to all enabled channels.
     *
     * @return list<PublishResult>
     */
    public function publishToAll(Content $content): array;

    /**
     * Unpublish content from all enabled channels.
     *
     * @return list<PublishResult>
     */
    public function unpublishFromAll(Content $content): array;
}
