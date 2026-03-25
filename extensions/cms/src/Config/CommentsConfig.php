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
 * @api
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
        $rawEnabled = $data['enabled'] ?? null;
        $rawAutoApprove = $data['auto_approve_authenticated'] ?? null;
        $rawEditWindow = $data['edit_window_minutes'] ?? null;
        $rawMaxNesting = $data['max_nesting_depth'] ?? null;
        $rawRateLimitMinute = $data['rate_limit_per_minute'] ?? null;
        $rawRateLimitHour = $data['rate_limit_per_hour'] ?? null;
        $rawGuestAllowed = $data['guest_comments_allowed'] ?? null;
        $rawRequireEmail = $data['require_email'] ?? null;
        $rawMaxBodyLength = $data['max_body_length'] ?? null;
        $rawMaxLinks = $data['max_links_per_comment'] ?? null;
        $rawHoneypot = $data['honeypot_field_name'] ?? null;

        return new self(
            enabled: is_bool($rawEnabled) ? $rawEnabled : true,
            autoApproveAuthenticated: is_bool($rawAutoApprove) ? $rawAutoApprove : false,
            editWindowMinutes: is_int($rawEditWindow) ? $rawEditWindow : 15,
            maxNestingDepth: is_int($rawMaxNesting) ? $rawMaxNesting : 3,
            rateLimitPerMinute: is_int($rawRateLimitMinute) ? $rawRateLimitMinute : 5,
            rateLimitPerHour: is_int($rawRateLimitHour) ? $rawRateLimitHour : 30,
            guestCommentsAllowed: is_bool($rawGuestAllowed) ? $rawGuestAllowed : true,
            requireEmail: is_bool($rawRequireEmail) ? $rawRequireEmail : false,
            maxBodyLength: is_int($rawMaxBodyLength) ? $rawMaxBodyLength : 10_000,
            maxLinksPerComment: is_int($rawMaxLinks) ? $rawMaxLinks : 3,
            honeypotFieldName: is_string($rawHoneypot) ? $rawHoneypot : 'website_url',
        );
    }
}
