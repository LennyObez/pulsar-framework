<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Publishing;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Publishing\ChannelRegistry;
use Pulsar\Extension\Cms\Publishing\PublishResult;
use Pulsar\Queue\QueueDriverInterface;
use Throwable;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Orchestrates publishing content across all enabled channels.
 *
 * When a queue driver is available, channel execution is dispatched
 * asynchronously. Otherwise, channels are invoked synchronously.
 */
#[Internal(reason: 'Triggered by PublishingStateMachine — not a public API')]
final readonly class PublishingOrchestrator
{
    public function __construct(
        private ChannelRegistry $registry,
        private ContentTranslationRepositoryInterface $translationRepository,
        private ?QueueDriverInterface $queueDriver = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Publish content to all enabled channels.
     *
     * @return list<PublishResult>
     */
    public function publishToAll(Content $content): array
    {
        $translations = $this->translationRepository->findByContentId($content->id);
        $results = [];

        foreach ($this->registry->getEnabled() as $channel) {
            foreach ($translations as $translation) {
                if ($this->queueDriver !== null) {
                    try {
                        $this->queueDriver->push(
                            'cms.publishing',
                            self::class,
                            json_encode([
                                'action' => 'publish',
                                'channel' => $channel->name(),
                                'content_id' => $content->id,
                                'translation_id' => $translation->id,
                            ], JSON_THROW_ON_ERROR),
                        );

                        $results[] = PublishResult::success($channel->name());
                    } catch (Throwable $e) {
                        $this->logger?->error(sprintf(
                            'Failed to queue publish for channel "%s": %s',
                            $channel->name(),
                            $e->getMessage(),
                        ));
                        $results[] = PublishResult::failure($channel->name(), $e->getMessage());
                    }
                } else {
                    try {
                        $results[] = $channel->publish($content, $translation);
                    } catch (Throwable $e) {
                        $this->logger?->error(sprintf(
                            'Channel "%s" failed to publish content %s: %s',
                            $channel->name(),
                            $content->id,
                            $e->getMessage(),
                        ));
                        $results[] = PublishResult::failure($channel->name(), $e->getMessage());
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Unpublish content from all enabled channels.
     *
     * @return list<PublishResult>
     */
    public function unpublishFromAll(Content $content): array
    {
        $results = [];

        foreach ($this->registry->getEnabled() as $channel) {
            try {
                $results[] = $channel->unpublish($content);
            } catch (Throwable $e) {
                $this->logger?->error(sprintf(
                    'Channel "%s" failed to unpublish content %s: %s',
                    $channel->name(),
                    $content->id,
                    $e->getMessage(),
                ));
                $results[] = PublishResult::failure($channel->name(), $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Publish content to a specific channel by name.
     */
    public function publishToChannel(Content $content, string $channelName): PublishResult
    {
        $channel = $this->registry->get($channelName);

        if ($channel === null) {
            return PublishResult::failure($channelName, sprintf('Channel "%s" not found', $channelName));
        }

        if (!$channel->isEnabled()) {
            return PublishResult::failure($channelName, sprintf('Channel "%s" is not enabled', $channelName));
        }

        $translations = $this->translationRepository->findByContentId($content->id);

        if ($translations === []) {
            return PublishResult::failure($channelName, 'No translations found for content');
        }

        // Publish with the first available translation
        return $channel->publish($content, $translations[0]);
    }
}
