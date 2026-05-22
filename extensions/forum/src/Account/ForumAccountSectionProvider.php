<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Account;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Account\AccountSection;
use Pulsar\Extension\Cms\Account\AccountSectionProviderInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

use function array_filter;
use function array_map;
use function count;
use function htmlspecialchars;
use function in_array;
use function max;
use function mb_strlen;
use function mb_substr;
use function sprintf;

use const ENT_QUOTES;

/**
 * Contributes "Forum Activity" and "Badges" tabs to the customer account view.
 *
 * Registered as a tagged service implementing AccountSectionProviderInterface
 * so the CMS account layout auto-discovers it.
 */
#[Internal]
final readonly class ForumAccountSectionProvider implements AccountSectionProviderInterface
{
    private const string SECTION_ACTIVITY = 'forum-activity';
    private const string SECTION_BADGES = 'badges';
    private const int ACTIVITY_PER_PAGE = 15;

    public function __construct(
        private ThreadRepositoryInterface $threads,
        private PostRepositoryInterface $posts,
        private BadgeServiceInterface $badges,
        private ForumProfileRepositoryInterface $profiles,
    ) {}

    /**
     * @return list<AccountSection>
     */
    #[Override]
    public function getSections(string $userId): array
    {
        $profile = $this->profiles->findByUser($userId);
        $badgeCount = $profile !== null
            ? count($this->badges->getUserBadges($userId))
            : 0;

        $activityCount = $profile !== null
            ? $profile->threadCount + $profile->postCount
            : 0;

        return [
            new AccountSection(
                id: self::SECTION_ACTIVITY,
                label: 'Forum Activity',
                icon: 'message-circle',
                priority: 40,
                badgeCount: $activityCount > 0 ? (string) $activityCount : null,
            ),
            new AccountSection(
                id: self::SECTION_BADGES,
                label: 'Badges',
                icon: 'award',
                priority: 45,
                badgeCount: $badgeCount > 0 ? (string) $badgeCount : null,
            ),
        ];
    }

    #[Override]
    public function renderFrontOffice(string $sectionId, string $userId, array $params = []): string
    {
        return match ($sectionId) {
            self::SECTION_ACTIVITY => $this->renderFrontActivity($userId, $params),
            self::SECTION_BADGES => $this->renderFrontBadges($userId),
            default => '',
        };
    }

    #[Override]
    public function renderBackOffice(string $sectionId, string $userId, array $params = []): string
    {
        return match ($sectionId) {
            self::SECTION_ACTIVITY => $this->renderBackActivity($userId, $params),
            self::SECTION_BADGES => $this->renderBackBadges($userId),
            default => '',
        };
    }

    /**
     * Build stats DTO from the user's forum profile and posts.
     */
    public function getStats(string $userId): ForumAccountStats
    {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            return new ForumAccountStats(
                threadCount: 0,
                replyCount: 0,
                bestAnswerCount: 0,
                reputation: 0,
                rank: ReputationLevel::Newcomer,
                joinedAt: new DateTimeImmutable(),
            );
        }

        $bestAnswerCount = $this->countBestAnswers($userId);

        return new ForumAccountStats(
            threadCount: $profile->threadCount,
            replyCount: $profile->postCount,
            bestAnswerCount: $bestAnswerCount,
            reputation: $profile->reputationScore,
            rank: $profile->reputationLevel(),
            joinedAt: $profile->createdAt,
        );
    }

    // -----------------------------------------------------------------------
    // Front-office renderers
    // -----------------------------------------------------------------------

    /**
     * Customer-facing: paginated list of the user's threads and replies.
     *
     * @param array<string, mixed> $params
     */
    private function renderFrontActivity(string $userId, array $params): string
    {
        $stats = $this->getStats($userId);
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, is_numeric($rawPage) ? (int) $rawPage : 1);

        $threadResult = $this->threads->findByAuthor($userId, $page, self::ACTIVITY_PER_PAGE);
        $postResult = $this->posts->findByAuthor($userId, $page, self::ACTIVITY_PER_PAGE);

        $html = $this->renderStatsHeader($stats);
        $html .= '<div class="forum-activity">';

        // Threads section
        $html .= '<h3 class="forum-activity__heading">Threads</h3>';

        if ($threadResult->isEmpty()) {
            $html .= '<p class="forum-activity__empty">No threads yet.</p>';
        } else {
            $html .= '<ul class="forum-activity__list">';

            /** @var Thread $thread */
            foreach ($threadResult->items as $thread) {
                $html .= $this->renderFrontThreadItem($thread);
            }

            $html .= '</ul>';
            $html .= $this->renderPagination($threadResult->currentPage, $threadResult->lastPage, 'threads');
        }

        // Replies section
        $html .= '<h3 class="forum-activity__heading">Replies</h3>';

        if ($postResult->isEmpty()) {
            $html .= '<p class="forum-activity__empty">No replies yet.</p>';
        } else {
            $html .= '<ul class="forum-activity__list">';

            /** @var Post $post */
            foreach ($postResult->items as $post) {
                $html .= $this->renderFrontPostItem($post);
            }

            $html .= '</ul>';
            $html .= $this->renderPagination($postResult->currentPage, $postResult->lastPage, 'replies');
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Customer-facing: earned badges grid with locked badges shown greyed out.
     */
    private function renderFrontBadges(string $userId): string
    {
        $earned = $this->badges->getUserBadges($userId);
        $earnedTypes = array_map(
            static fn(UserBadge $ub): Badge => $ub->badge,
            $earned,
        );

        $html = '<div class="forum-badges">';
        $html .= '<h3 class="forum-badges__heading">Achievement Badges</h3>';
        $html .= '<div class="forum-badges__grid">';

        foreach (Badge::cases() as $badge) {
            $isEarned = in_array($badge, $earnedTypes, true);
            $html .= $this->renderBadgeCard($badge, $isEarned);
        }

        $html .= '</div></div>';

        return $html;
    }

    // -----------------------------------------------------------------------
    // Back-office renderers
    // -----------------------------------------------------------------------

    /**
     * Admin-facing: full posting history with moderation status and thread links.
     *
     * @param array<string, mixed> $params
     */
    private function renderBackActivity(string $userId, array $params): string
    {
        $stats = $this->getStats($userId);
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, is_numeric($rawPage) ? (int) $rawPage : 1);
        $profile = $this->profiles->findByUser($userId);

        $threadResult = $this->threads->findByAuthor($userId, $page, self::ACTIVITY_PER_PAGE);
        $postResult = $this->posts->findByAuthor($userId, $page, self::ACTIVITY_PER_PAGE);

        $html = $this->renderStatsHeader($stats);

        // Ban status for admins
        if ($profile !== null && $profile->isBanned) {
            $reason = self::escape($profile->banReason ?? 'No reason provided');
            $html .= sprintf(
                '<div class="forum-activity__ban-notice">User is banned: %s</div>',
                $reason,
            );
        }

        $html .= '<div class="forum-activity">';

        // Threads table
        $html .= '<h3 class="forum-activity__heading">Threads</h3>';

        if ($threadResult->isEmpty()) {
            $html .= '<p class="forum-activity__empty">No threads.</p>';
        } else {
            $html .= '<table class="forum-activity__table">';
            $html .= '<thead><tr>'
                . '<th>Title</th>'
                . '<th>Status</th>'
                . '<th>Replies</th>'
                . '<th>Votes</th>'
                . '<th>Created</th>'
                . '</tr></thead><tbody>';

            /** @var Thread $thread */
            foreach ($threadResult->items as $thread) {
                $html .= $this->renderBackThreadRow($thread);
            }

            $html .= '</tbody></table>';
            $html .= $this->renderPagination($threadResult->currentPage, $threadResult->lastPage, 'threads');
        }

        // Posts table
        $html .= '<h3 class="forum-activity__heading">Replies</h3>';

        if ($postResult->isEmpty()) {
            $html .= '<p class="forum-activity__empty">No replies.</p>';
        } else {
            $html .= '<table class="forum-activity__table">';
            $html .= '<thead><tr>'
                . '<th>Thread</th>'
                . '<th>Excerpt</th>'
                . '<th>Solution</th>'
                . '<th>Votes</th>'
                . '<th>Edits</th>'
                . '<th>Created</th>'
                . '</tr></thead><tbody>';

            /** @var Post $post */
            foreach ($postResult->items as $post) {
                $html .= $this->renderBackPostRow($post);
            }

            $html .= '</tbody></table>';
            $html .= $this->renderPagination($postResult->currentPage, $postResult->lastPage, 'replies');
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Admin-facing: all earned badges with award dates.
     */
    private function renderBackBadges(string $userId): string
    {
        $earned = $this->badges->getUserBadges($userId);

        $html = '<div class="forum-badges">';
        $html .= '<h3 class="forum-badges__heading">Earned Badges</h3>';

        if ($earned === []) {
            $html .= '<p class="forum-badges__empty">No badges earned.</p>';
            $html .= '</div>';

            return $html;
        }

        $html .= '<table class="forum-badges__table">';
        $html .= '<thead><tr>'
            . '<th>Badge</th>'
            . '<th>Description</th>'
            . '<th>Awarded</th>'
            . '</tr></thead><tbody>';

        foreach ($earned as $userBadge) {
            $label = self::escape($userBadge->badge->label());
            $description = self::escape($userBadge->badge->description());
            $awardedAt = $userBadge->awardedAt->format('Y-m-d H:i');

            $html .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                $label,
                $description,
                $awardedAt,
            );
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    // -----------------------------------------------------------------------
    // Shared rendering helpers
    // -----------------------------------------------------------------------

    private function renderStatsHeader(ForumAccountStats $stats): string
    {
        $rank = self::escape($stats->rank->label());

        return sprintf(
            '<div class="forum-stats">'
            . '<div class="forum-stats__item"><span class="forum-stats__value">%d</span><span class="forum-stats__label">Threads</span></div>'
            . '<div class="forum-stats__item"><span class="forum-stats__value">%d</span><span class="forum-stats__label">Replies</span></div>'
            . '<div class="forum-stats__item"><span class="forum-stats__value">%d</span><span class="forum-stats__label">Best Answers</span></div>'
            . '<div class="forum-stats__item"><span class="forum-stats__value">%d</span><span class="forum-stats__label">Reputation</span></div>'
            . '<div class="forum-stats__item"><span class="forum-stats__value">%s</span><span class="forum-stats__label">Rank</span></div>'
            . '<div class="forum-stats__item"><span class="forum-stats__value">%s</span><span class="forum-stats__label">Member Since</span></div>'
            . '</div>',
            $stats->threadCount,
            $stats->replyCount,
            $stats->bestAnswerCount,
            $stats->reputation,
            $rank,
            $stats->joinedAt->format('M j, Y'),
        );
    }

    private function renderFrontThreadItem(Thread $thread): string
    {
        $title = self::escape($thread->title);
        $status = self::escape($thread->status->label());
        $date = $thread->createdAt->format('M j, Y');
        $solved = $thread->isSolved() ? ' <span class="forum-activity__solved">Solved</span>' : '';

        return sprintf(
            '<li class="forum-activity__item">'
            . '<a href="/forum/thread/%s" class="forum-activity__link">%s</a>%s'
            . '<span class="forum-activity__meta">'
            . '<span class="forum-activity__status">%s</span>'
            . ' &middot; %d replies &middot; %d votes &middot; %s'
            . '</span>'
            . '</li>',
            self::escape($thread->slug),
            $title,
            $solved,
            $status,
            $thread->replyCount,
            $thread->voteScore,
            $date,
        );
    }

    private function renderFrontPostItem(Post $post): string
    {
        $excerpt = self::escape(self::excerpt($post->body, 120));
        $date = $post->createdAt->format('M j, Y');
        $solution = $post->isSolution ? ' <span class="forum-activity__solved">Best Answer</span>' : '';
        $threadId = self::escape($post->threadId);

        return sprintf(
            '<li class="forum-activity__item">'
            . '<a href="/forum/thread/%s#post-%s" class="forum-activity__link">%s</a>%s'
            . '<span class="forum-activity__meta">'
            . '%d votes &middot; %s'
            . '</span>'
            . '</li>',
            $threadId,
            self::escape($post->id),
            $excerpt,
            $solution,
            $post->voteScore,
            $date,
        );
    }

    private function renderBackThreadRow(Thread $thread): string
    {
        $title = self::escape($thread->title);
        $status = self::escape($thread->status->label());
        $slug = self::escape($thread->slug);
        $date = $thread->createdAt->format('Y-m-d H:i');
        $deleted = $thread->isDeleted() ? ' <span class="forum-activity__deleted">(deleted)</span>' : '';

        return sprintf(
            '<tr>'
            . '<td><a href="/admin/forum/threads/%s">%s</a>%s</td>'
            . '<td>%s</td>'
            . '<td>%d</td>'
            . '<td>%d</td>'
            . '<td>%s</td>'
            . '</tr>',
            $slug,
            $title,
            $deleted,
            $status,
            $thread->replyCount,
            $thread->voteScore,
            $date,
        );
    }

    private function renderBackPostRow(Post $post): string
    {
        $threadId = self::escape($post->threadId);
        $excerpt = self::escape(self::excerpt($post->body, 80));
        $solution = $post->isSolution ? 'Yes' : 'No';
        $date = $post->createdAt->format('Y-m-d H:i');
        $deleted = $post->isDeleted() ? ' <span class="forum-activity__deleted">(deleted)</span>' : '';

        return sprintf(
            '<tr>'
            . '<td><a href="/admin/forum/threads/%s">%s</a></td>'
            . '<td>%s%s</td>'
            . '<td>%s</td>'
            . '<td>%d</td>'
            . '<td>%d</td>'
            . '<td>%s</td>'
            . '</tr>',
            $threadId,
            $threadId,
            $excerpt,
            $deleted,
            $solution,
            $post->voteScore,
            $post->editCount,
            $date,
        );
    }

    private function renderBadgeCard(Badge $badge, bool $isEarned): string
    {
        $label = self::escape($badge->label());
        $description = self::escape($badge->description());
        $stateClass = $isEarned ? 'forum-badges__card--earned' : 'forum-badges__card--locked';

        return sprintf(
            '<div class="forum-badges__card %s">'
            . '<div class="forum-badges__icon">%s</div>'
            . '<div class="forum-badges__label">%s</div>'
            . '<div class="forum-badges__description">%s</div>'
            . '</div>',
            $stateClass,
            $label,
            $label,
            $description,
        );
    }

    private function renderPagination(?int $currentPage, ?int $lastPage, string $section): string
    {
        if ($currentPage === null || $lastPage === null || $lastPage <= 1) {
            return '';
        }

        $items = '';

        for ($i = 1; $i <= $lastPage; $i++) {
            $activeClass = $i === $currentPage ? ' forum-pagination__link--active' : '';

            $items .= sprintf(
                '<a href="?section=%s&page=%d" class="forum-pagination__link%s">%d</a>',
                self::escape($section),
                $i,
                $activeClass,
                $i,
            );
        }

        return sprintf('<nav class="forum-pagination" aria-label="Pagination">%s</nav>', $items);
    }

    /**
     * Count posts marked as best answer/solution for a user.
     *
     * Iterates through pages of user posts. For most users the post count
     * is small enough that this completes within 1-2 pages.
     */
    private function countBestAnswers(string $userId): int
    {
        $count = 0;
        $page = 1;

        do {
            $result = $this->posts->findByAuthor($userId, $page, 100);

            $count += count(array_filter(
                $result->items,
                static fn(Post $post): bool => $post->isSolution,
            ));

            $page++;
        } while ($result->hasMore);

        return $count;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function excerpt(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '...';
    }
}
