<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Account;

use Pulsar\Api\Api;

/**
 * Represents a section/tab in the customer account view.
 *
 * Extensions register these via AccountSectionProviderInterface to contribute
 * their own tabs to both front-office (/account/) and back-office (/admin/customers/{id}) views.
 *
 * @psalm-api Public DTO returned from AccountSectionProviderInterface; consumed
 *            by AccountController and Studio account templates.
 */
#[Api(since: '1.0.0')]
final readonly class AccountSection
{
    /**
     * @param non-empty-string $id Unique section identifier (e.g., 'orders', 'forum-activity')
     * @param non-empty-string $label Human-readable tab label (already translated)
     * @param non-empty-string $icon Icon identifier (e.g., 'shopping-bag', 'message-circle')
     * @param int $priority Sort order: lower = displayed first (default: 50)
     * @param string|null $badgeCount Optional count badge (e.g., "3" for 3 pending orders)
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $icon,
        public int $priority = 50,
        public ?string $badgeCount = null,
    ) {}
}
