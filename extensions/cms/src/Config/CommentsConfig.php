<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array{
     *     enabled?: bool,
     *     auto_approve_authenticated?: bool,
     *     edit_window_minutes?: int,
     *     max_nesting_depth?: int,
     *     rate_limit_per_minute?: int,
     *     rate_limit_per_hour?: int,
     *     guest_comments_allowed?: bool,
     *     require_email?: bool,
     *     max_body_length?: int,
     *     max_links_per_comment?: int,
     *     honeypot_field_name?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null, true),
            autoApproveAuthenticated: Coerce::strictBool($data['auto_approve_authenticated'] ?? null),
            editWindowMinutes: Coerce::int($data['edit_window_minutes'] ?? null, 15),
            maxNestingDepth: Coerce::int($data['max_nesting_depth'] ?? null, 3),
            rateLimitPerMinute: Coerce::int($data['rate_limit_per_minute'] ?? null, 5),
            rateLimitPerHour: Coerce::int($data['rate_limit_per_hour'] ?? null, 30),
            guestCommentsAllowed: Coerce::strictBool($data['guest_comments_allowed'] ?? null, true),
            requireEmail: Coerce::strictBool($data['require_email'] ?? null),
            maxBodyLength: Coerce::int($data['max_body_length'] ?? null, 10_000),
            maxLinksPerComment: Coerce::int($data['max_links_per_comment'] ?? null, 3),
            honeypotFieldName: Coerce::string($data['honeypot_field_name'] ?? null, 'website_url'),
        );
    }
}
