<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;

/**
 * Shared view rendering for admin server controllers.
 *
 * Each controller passes a different template name (content) and title;
 * the layout include and ob_start/ob_get_clean boilerplate is identical.
 */
#[Internal]
trait RendersAdminLayout
{
    /**
     * @param array<string, mixed> $templateData
     */
    private function renderAdminView(string $title, string $content, array $templateData): string
    {
        extract(['title' => $title, 'content' => $content, 'templateData' => $templateData]);
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
