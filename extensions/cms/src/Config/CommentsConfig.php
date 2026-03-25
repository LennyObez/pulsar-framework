<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Comments system configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by CommentService and admin moderation views.
 */
#[Api(since: '1.0.0')]
final readonly class CommentsConfig
{
    /**
     * @param bool $enabled Whether the comments system is globally enabled
     * @param bool $autoApproveAuthenticated Auto-approve comments from authenticated users
     * @param int $editWindowMinutes Minutes after posting during which author can edit
     * @param int $maxNestingDepth Maximum reply nesting depth
     * @param int $rateLimitPerMinute Maximum comments per minute per user/IP
     * @param int $rateLimitPerHour Maximum comments per hour per user/IP
     * @param bool $guestCommentsAllowed Whether unauthenticated users can comment
     * @param bool $requireEmail Whether guest commenters must provide an email
     * @param int $maxBodyLength Maximum comment body length in characters
     * @param int $maxLinksPerComment Maximum number of links allowed per comment
     * @param string $honeypotFieldName Hidden field name for bot detection
     */
    public function __construct(
        public bool $enabled = true,
        public bool $autoApproveAuthenticated = false,
        public int $editWindowMinutes = 15,
        public int $maxNestingDepth = 3,
        public int $rateLimitPerMinute = 5,
        public int $rateLimitPerHour = 30,
        public bool $guestCommentsAllowed = true,
        public bool $requireEmail = false,
        public int $maxBodyLength = 10_000,
        public int $maxLinksPerComment = 3,
        public string $honeypotFieldName = 'website_url',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : true,
            autoApproveAuthenticated: is_bool($data['auto_approve_authenticated'] ?? null) ? $data['auto_approve_authenticated'] : false,
            editWindowMinutes: is_int($data['edit_window_minutes'] ?? null) ? $data['edit_window_minutes'] : 15,
            maxNestingDepth: is_int($data['max_nesting_depth'] ?? null) ? $data['max_nesting_depth'] : 3,
            rateLimitPerMinute: is_int($data['rate_limit_per_minute'] ?? null) ? $data['rate_limit_per_minute'] : 5,
            rateLimitPerHour: is_int($data['rate_limit_per_hour'] ?? null) ? $data['rate_limit_per_hour'] : 30,
            guestCommentsAllowed: is_bool($data['guest_comments_allowed'] ?? null) ? $data['guest_comments_allowed'] : true,
            requireEmail: is_bool($data['require_email'] ?? null) ? $data['require_email'] : false,
            maxBodyLength: is_int($data['max_body_length'] ?? null) ? $data['max_body_length'] : 10_000,
            maxLinksPerComment: is_int($data['max_links_per_comment'] ?? null) ? $data['max_links_per_comment'] : 3,
            honeypotFieldName: is_string($data['honeypot_field_name'] ?? null) ? $data['honeypot_field_name'] : 'website_url',
        );
    }
}
