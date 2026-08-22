<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Internal;

/**
 * Dashboard widget providing quick action links for common CMS operations.
 *
 * Renders "New Article", "New Page", "Upload Media", and "View Site" links.
 *
 * @psalm-api Resolved by the admin DashboardWidget registry; not new'd by name.
 */
#[Internal(reason: 'CMS dashboard widget; implementation detail')]
final readonly class QuickActionsWidget implements DashboardWidgetInterface
{
    public function __construct(
        private string $siteUrl = '/',
    ) {}

    public function getName(): string
    {
        return 'quick_actions';
    }

    public function getData(): array
    {
        return [
            'actions' => [
                [
                    'label' => 'New Article',
                    'url' => '/admin/cms/content/create?type=article',
                    'icon' => 'article',
                ],
                [
                    'label' => 'New Page',
                    'url' => '/admin/cms/content/create?type=page',
                    'icon' => 'page',
                ],
                [
                    'label' => 'Upload Media',
                    'url' => '/admin/cms/media/upload',
                    'icon' => 'upload',
                ],
                [
                    'label' => 'View Site',
                    'url' => $this->siteUrl,
                    'icon' => 'external-link',
                    'target' => '_blank',
                ],
            ],
        ];
    }

    public function getTemplate(): string
    {
        return 'dashboard/widgets/quick-actions';
    }
}
