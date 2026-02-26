<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Account;

use Pulsar\Api\Api;

/**
 * Extensions implement this interface to contribute sections/tabs to the customer account.
 *
 * Both front-office (customer-facing) and back-office (admin-facing) account views
 * collect providers from the container and render each registered section as a tab.
 *
 * Example: The Forum extension registers "Forum Activity" and "Badges" tabs.
 * Example: The Payments extension registers "Orders", "Invoices", and "Payment Methods" tabs.
 *
 * @psalm-api Public extension contract; implementations are tagged with
 *            cms.account.section_provider and aggregated by AccountSectionRegistry.
 */
#[Api(since: '1.0.0')]
interface AccountSectionProviderInterface
{
    /**
     * Return the sections this provider contributes to the account view.
     *
     * @param string $userId The user whose account is being viewed
     * @return list<AccountSection>
     */
    public function getSections(string $userId): array;

    /**
     * Render a section's HTML content for the front-office account page.
     *
     * The rendered HTML will be placed inside the account layout's tab panel.
     *
     * @param non-empty-string $sectionId Must match one of getSections()[*]->id
     * @param string $userId The authenticated user viewing their own account
     * @param array<string, mixed> $params Additional parameters (e.g., pagination page)
     * @return string Rendered HTML content
     */
    public function renderFrontOffice(string $sectionId, string $userId, array $params = []): string;

    /**
     * Render a section's HTML content for the back-office admin customer detail page.
     *
     * Admins see a read-only or management view of any customer's data.
     *
     * @param non-empty-string $sectionId Must match one of getSections()[*]->id
     * @param string $userId The customer being viewed by the admin
     * @param array<string, mixed> $params Additional parameters
     * @return string Rendered HTML content
     */
    public function renderBackOffice(string $sectionId, string $userId, array $params = []): string;
}
