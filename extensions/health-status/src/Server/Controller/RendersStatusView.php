<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Server\Controller;

use Pulsar\Api\Internal;

/**
 * Shared view rendering for health-status server controllers.
 *
 * Each controller passes a template name, page title, and data;
 * the layout wrapper and ob_start/ob_get_clean boilerplate are reused.
 */
#[Internal]
trait RendersStatusView
{
    /**
     * Render a health-status view wrapped in the shared layout.
     *
     * @param array<string, mixed> $templateData Data available inside the content template
     */
    private function renderView(string $title, string $contentTemplate, array $templateData = []): string
    {
        $content = $this->renderContentTemplate($contentTemplate, $templateData);

        extract(['title' => $title, 'content' => $content]);
        ob_start();
        include __DIR__ . '/../View/templates/layout.pulse.php';

        return (string) ob_get_clean();
    }

    /**
     * Render a content-area template to a string.
     *
     * @param array<string, mixed> $data
     */
    private function renderContentTemplate(string $template, array $data): string
    {
        $templatePath = __DIR__ . '/../View/templates/' . $template . '.pulse.php';
        extract($data);
        ob_start();
        include $templatePath;

        return (string) ob_get_clean();
    }
}
