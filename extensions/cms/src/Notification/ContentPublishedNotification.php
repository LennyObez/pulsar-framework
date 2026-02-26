<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Notification;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Notification dispatched when content is published.
 */
#[Api(since: '1.0.0')]
final readonly class ContentPublishedNotification implements CmsNotificationInterface
{
    /**
     * @param list<string> $recipientUserIds User IDs to notify (e.g. content author, editors)
     */
    public function __construct(
        public string $contentId,
        public string $contentTitle,
        public string $authorId,
        public DateTimeImmutable $publishedAt,
        public string $url,
        private array $recipientUserIds = [],
    ) {}

    public function type(): string
    {
        return 'content_published';
    }

    public function recipientIds(): array
    {
        return $this->recipientUserIds;
    }

    public function subject(): string
    {
        return "Content published: {$this->contentTitle}";
    }

    public function body(): string
    {
        return "The content \"{$this->contentTitle}\" has been published and is now live at {$this->url}.";
    }

    public function metadata(): array
    {
        return [
            'content_id' => $this->contentId,
            'author_id' => $this->authorId,
            'published_at' => $this->publishedAt->format('c'),
            'url' => $this->url,
        ];
    }
}
