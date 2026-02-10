<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Publishing;

use Pulsar\Api\Api;

/**
 * Registry of publishing channels.
 *
 * Channels are registered during boot. The registry is mutable to support
 * extension/plugin-provided channels registered at runtime.
 */
#[Api(since: '1.0.0')]
final class ChannelRegistry
{
    /** @var array<string, PublishingChannelInterface> */
    private array $channels = [];

    public function register(PublishingChannelInterface $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function get(string $name): ?PublishingChannelInterface
    {
        return $this->channels[$name] ?? null;
    }

    /**
     * @return list<PublishingChannelInterface>
     */
    public function getEnabled(): array
    {
        $enabled = [];

        foreach ($this->channels as $channel) {
            if ($channel->isEnabled()) {
                $enabled[] = $channel;
            }
        }

        return $enabled;
    }

    /**
     * @return list<PublishingChannelInterface>
     */
    public function all(): array
    {
        return array_values($this->channels);
    }
}
