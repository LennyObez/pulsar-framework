<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Publishing;

use Pulsar\Api\Api;

/**
 * Result of a publish or unpublish operation on a single channel.
 *
 * @psalm-api Public DTO returned from PublishingChannelInterface and the
 *            orchestrator; consumed by user-land code and admin views.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PublishResult
{
    public function __construct(
        public bool $success,
        public string $channelName,
        public ?string $externalUrl = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(string $channelName, ?string $externalUrl = null): self
    {
        return new self(
            success: true,
            channelName: $channelName,
            externalUrl: $externalUrl,
        );
    }

    /**
     * Create a result indicating the publish operation was queued for async processing.
     */
    public static function queued(string $channelName): self
    {
        return new self(
            success: true,
            channelName: $channelName,
            errorMessage: null,
        );
    }

    /**
     * Whether this result represents a queued (deferred) operation.
     */
    public function isQueued(): bool
    {
        return $this->success && $this->externalUrl === null && $this->errorMessage === null;
    }

    public static function failure(string $channelName, string $error): self
    {
        return new self(
            success: false,
            channelName: $channelName,
            errorMessage: $error,
        );
    }
}
