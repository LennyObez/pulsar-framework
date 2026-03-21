<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;

use function is_array;
use function is_int;

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

    private static function int(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function subArray(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            threadsPerPage: self::int($data['threads_per_page'] ?? null, 25),
            postsPerPage: self::int($data['posts_per_page'] ?? null, 20),
            postCooldownSeconds: self::int($data['post_cooldown_seconds'] ?? null, 30),
            requireThreadApproval: (bool) ($data['require_thread_approval'] ?? false),
            allowGuestViewing: (bool) ($data['allow_guest_viewing'] ?? true),
            maxTitleLength: self::int($data['max_title_length'] ?? null, 200),
            maxBodyLength: self::int($data['max_body_length'] ?? null, 50_000),
            maxTagsPerThread: self::int($data['max_tags_per_thread'] ?? null, 5),
            editWindowMinutes: self::int($data['edit_window_minutes'] ?? null, 30),
            moderation: ModerationConfig::fromArray(self::subArray($data, 'moderation')),
            reputation: ReputationConfig::fromArray(self::subArray($data, 'reputation')),
            badges: BadgeConfig::fromArray(self::subArray($data, 'badges')),
        );
    }
}
