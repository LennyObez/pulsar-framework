<?php

declare(strict_types=1);

/**
 * Forum configuration.
 *
 * @see \Pulsar\Extension\Forum\Config\ForumConfig
 */
return [
    // Maximum threads per page in listings
    'threads_per_page' => 25,

    // Maximum posts per page in thread view
    'posts_per_page' => 20,

    // Minimum seconds between consecutive posts by the same user
    'post_cooldown_seconds' => 30,

    // Whether new threads require moderator approval
    'require_thread_approval' => false,

    // Whether guests can view forum content (read-only)
    'allow_guest_viewing' => true,

    // Maximum length of a thread title in characters
    'max_title_length' => 200,

    // Maximum length of a post body in characters
    'max_body_length' => 50_000,

    // Maximum number of tags per thread
    'max_tags_per_thread' => 5,

    // Edit window in minutes after posting (0 = unlimited)
    'edit_window_minutes' => 30,

    // Moderation thresholds
    'moderation' => [
        // Number of reports before auto-hiding content
        'auto_hide_threshold' => 5,

        // Number of reports before notifying moderators
        'notify_threshold' => 3,

        // Days to retain dismissed reports
        'dismissed_report_retention_days' => 90,
    ],

    // Reputation system
    'reputation' => [
        // Points awarded for creating a thread
        'points_per_thread' => 2,

        // Points awarded for creating a post/reply
        'points_per_post' => 1,

        // Points awarded when receiving an upvote
        'points_per_upvote' => 5,

        // Points deducted when receiving a downvote
        'points_per_downvote' => -2,

        // Points awarded when a post is marked as solution
        'points_per_solution' => 15,

        // Minimum reputation required to downvote
        'min_reputation_to_downvote' => 50,
    ],

    // Badge system
    'badges' => [
        // Whether the badge system is enabled
        'enabled' => true,

        // Upvotes needed on answers for Helpful badge
        'helpful_upvote_threshold' => 10,

        // Views needed on a thread for PopularThread badge
        'popular_thread_view_threshold' => 50,

        // Accepted answers needed for Solver badge
        'solver_accepted_answer_threshold' => 10,

        // Confirmed bug reports needed for BugHunter badge
        'bug_hunter_confirmed_threshold' => 5,

        // Distinct locales posted in for Multilingual badge
        'multilingual_locale_threshold' => 2,
    ],
];
