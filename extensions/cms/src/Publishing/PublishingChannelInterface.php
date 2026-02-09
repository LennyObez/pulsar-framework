<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Publishing;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;

/**
 * Contract for a publishing output channel.
 *
 * Each channel represents a delivery target (web, RSS, static site, etc.)
 * and is invoked by the PublishingOrchestrator when content transitions
 * to or from the Published state.
 */
#[Api(since: '1.0.0')]
interface PublishingChannelInterface
{
    /**
     * Unique channel identifier (e.g., 'web', 'rss', 'static').
     */
    public function name(): string;

    /**
     * Publish a content item and its translation to this channel.
     */
    public function publish(Content $content, ContentTranslation $translation): PublishResult;

    /**
     * Remove a content item from this channel.
     */
    public function unpublish(Content $content): PublishResult;

    /**
     * Whether this channel is currently enabled.
     */
    public function isEnabled(): bool;
}
