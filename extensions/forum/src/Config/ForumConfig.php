<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

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
        $mod = $data['moderation'] ?? null;
        $rep = $data['reputation'] ?? null;
        $bad = $data['badges'] ?? null;

        return new self(
            threadsPerPage: Coerce::int($data['threads_per_page'] ?? null, 25),
            postsPerPage: Coerce::int($data['posts_per_page'] ?? null, 20),
            postCooldownSeconds: Coerce::int($data['post_cooldown_seconds'] ?? null, 30),
            requireThreadApproval: (bool) ($data['require_thread_approval'] ?? false),
            allowGuestViewing: (bool) ($data['allow_guest_viewing'] ?? true),
            maxTitleLength: Coerce::int($data['max_title_length'] ?? null, 200),
            maxBodyLength: Coerce::int($data['max_body_length'] ?? null, 50_000),
            maxTagsPerThread: Coerce::int($data['max_tags_per_thread'] ?? null, 5),
            editWindowMinutes: Coerce::int($data['edit_window_minutes'] ?? null, 30),
            moderation: ModerationConfig::fromArray(is_array($mod) ? $mod : []),
            reputation: ReputationConfig::fromArray(is_array($rep) ? $rep : []),
            badges: BadgeConfig::fromArray(is_array($bad) ? $bad : []),
        );
    }
}
