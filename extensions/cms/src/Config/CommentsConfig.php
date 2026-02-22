<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * Comments system configuration.
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
            enabled: (bool) ($data['enabled'] ?? true),
            autoApproveAuthenticated: (bool) ($data['auto_approve_authenticated'] ?? false),
            editWindowMinutes: (int) ($data['edit_window_minutes'] ?? 15),
            maxNestingDepth: (int) ($data['max_nesting_depth'] ?? 3),
            rateLimitPerMinute: (int) ($data['rate_limit_per_minute'] ?? 5),
            rateLimitPerHour: (int) ($data['rate_limit_per_hour'] ?? 30),
            guestCommentsAllowed: (bool) ($data['guest_comments_allowed'] ?? true),
            requireEmail: (bool) ($data['require_email'] ?? false),
            maxBodyLength: (int) ($data['max_body_length'] ?? 10_000),
            maxLinksPerComment: (int) ($data['max_links_per_comment'] ?? 3),
            honeypotFieldName: (string) ($data['honeypot_field_name'] ?? 'website_url'),
        );
    }
}
