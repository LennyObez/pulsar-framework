<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Publishing;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Publishing\PublishingChannelInterface;
use Pulsar\Extension\Cms\Publishing\PublishResult;

/**
 * Default web publishing channel.
 *
 * Web publishing is handled by the content controller: this channel
 * always succeeds as a no-op to confirm the web channel participated.
 *
 * @psalm-api Registered with the ChannelRegistry by the CMS service provider;
 *            invoked via PublishingChannelInterface, not instantiated by name.
 */
#[Internal(reason: 'Use PublishingChannelInterface for public API')]
final readonly class WebChannel implements PublishingChannelInterface
{
    public function name(): string
    {
        return 'web';
    }

    public function publish(Content $content, ContentTranslation $translation): PublishResult
    {
        return PublishResult::success($this->name(), '/' . ltrim($translation->path, '/'));
    }

    public function unpublish(Content $content): PublishResult
    {
        return PublishResult::success($this->name());
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
