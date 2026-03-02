<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

/**
 * Admin controller for forum settings management.
 *
 * Displays the current forum configuration. Settings are read-only via
 * the HTTP layer; changes are applied via the config file (config/forum.php).
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class SettingsController
{
    use RendersAdminView;

    public function __construct(
        private ForumConfig $config,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/settings: View forum settings.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.settings');

        $data = [
            'settings' => [
                'threads_per_page' => $this->config->threadsPerPage,
                'posts_per_page' => $this->config->postsPerPage,
                'post_cooldown_seconds' => $this->config->postCooldownSeconds,
                'require_thread_approval' => $this->config->requireThreadApproval,
                'allow_guest_viewing' => $this->config->allowGuestViewing,
                'max_title_length' => $this->config->maxTitleLength,
                'max_body_length' => $this->config->maxBodyLength,
                'max_tags_per_thread' => $this->config->maxTagsPerThread,
                'edit_window_minutes' => $this->config->editWindowMinutes,
                'moderation' => [
                    'auto_hide_threshold' => $this->config->moderation->autoHideThreshold,
                    'notify_threshold' => $this->config->moderation->notifyThreshold,
                    'dismissed_report_retention_days' => $this->config->moderation->dismissedReportRetentionDays,
                ],
                'reputation' => [
                    'points_per_thread' => $this->config->reputation->pointsPerThread,
                    'points_per_post' => $this->config->reputation->pointsPerPost,
                    'points_per_upvote' => $this->config->reputation->pointsPerUpvote,
                    'points_per_downvote' => $this->config->reputation->pointsPerDownvote,
                    'points_per_solution' => $this->config->reputation->pointsPerSolution,
                    'min_reputation_to_downvote' => $this->config->reputation->minReputationToDownvote,
                ],
                'badges' => [
                    'enabled' => $this->config->badges->enabled,
                    'helpful_upvote_threshold' => $this->config->badges->helpfulUpvoteThreshold,
                    'popular_thread_view_threshold' => $this->config->badges->popularThreadViewThreshold,
                    'solver_accepted_answer_threshold' => $this->config->badges->solverAcceptedAnswerThreshold,
                    'bug_hunter_confirmed_threshold' => $this->config->badges->bugHunterConfirmedThreshold,
                    'multilingual_locale_threshold' => $this->config->badges->multilingualLocaleThreshold,
                ],
            ],
        ];

        return $this->respondWithView($request, 'admin.forum.settings.index', $data);
    }
}
