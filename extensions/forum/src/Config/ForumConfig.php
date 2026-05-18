<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;

/**
 * Top-level forum configuration DTO.
 *
 * Loaded from config/forum.php during the preBoot phase. All values have
 * sensible defaults suitable for general community forums.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumConfig
{
    /**
     * @param int $threadsPerPage Maximum threads per page in listings
     * @param int $postsPerPage Maximum posts per page in thread view
     * @param int $postCooldownSeconds Minimum seconds between consecutive posts
     * @param bool $requireThreadApproval Whether new threads require moderator approval
     * @param bool $allowGuestViewing Whether guests can view forum content
     * @param int $maxTitleLength Maximum thread title length in characters
     * @param int $maxBodyLength Maximum post body length in characters
     * @param int $maxTagsPerThread Maximum tags per thread
     * @param int $editWindowMinutes Edit window after posting (0 = unlimited)
     * @param ModerationConfig $moderation Moderation thresholds
     * @param ReputationConfig $reputation Reputation system configuration
     * @param BadgeConfig $badges Badge system configuration
     */
    public function __construct(
        public int $threadsPerPage = 25,
        public int $postsPerPage = 20,
        public int $postCooldownSeconds = 30,
        public bool $requireThreadApproval = false,
        public bool $allowGuestViewing = true,
        public int $maxTitleLength = 200,
        public int $maxBodyLength = 50_000,
        public int $maxTagsPerThread = 5,
        public int $editWindowMinutes = 30,
        public ModerationConfig $moderation = new ModerationConfig(),
        public ReputationConfig $reputation = new ReputationConfig(),
        public BadgeConfig $badges = new BadgeConfig(),
    ) {}

    /**
     * @param array{
     *     threads_per_page?: int,
     *     posts_per_page?: int,
     *     post_cooldown_seconds?: int,
     *     require_thread_approval?: bool|int|string,
     *     allow_guest_viewing?: bool|int|string,
     *     max_title_length?: int,
     *     max_body_length?: int,
     *     max_tags_per_thread?: int,
     *     edit_window_minutes?: int,
     *     moderation?: array<string, mixed>,
     *     reputation?: array<string, mixed>,
     *     badges?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            threadsPerPage: $data['threads_per_page'] ?? 25,
            postsPerPage: $data['posts_per_page'] ?? 20,
            postCooldownSeconds: $data['post_cooldown_seconds'] ?? 30,
            requireThreadApproval: (bool) ($data['require_thread_approval'] ?? false),
            allowGuestViewing: (bool) ($data['allow_guest_viewing'] ?? true),
            maxTitleLength: $data['max_title_length'] ?? 200,
            maxBodyLength: $data['max_body_length'] ?? 50_000,
            maxTagsPerThread: $data['max_tags_per_thread'] ?? 5,
            editWindowMinutes: $data['edit_window_minutes'] ?? 30,
            moderation: ModerationConfig::fromArray($data['moderation'] ?? []),
            reputation: ReputationConfig::fromArray($data['reputation'] ?? []),
            badges: BadgeConfig::fromArray($data['badges'] ?? []),
        );
    }
}
