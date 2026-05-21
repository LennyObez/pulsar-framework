<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use Pulsar\Api\Internal;

/**
 * Shared view rendering for Studio server controllers.
 *
 * Each controller passes a template name, page title, and data;
 * the layout wrapper and ob_start/ob_get_clean boilerplate are reused.
 */
#[Internal]
trait RendersStudioView
{
    /**
     * Render a Studio view wrapped in the shared layout.
     *
     * @param array<string, mixed> $templateData Data available inside the content template.
     */
    private function renderStudioView(string $title, string $contentTemplate, array $templateData = []): string
    {
        // Render the content template first
        $content = $this->renderContentTemplate($contentTemplate, $templateData);

        // Wrap in layout
        extract(['title' => $title, 'content' => $content]);
        ob_start();
        include __DIR__ . '/../View/templates/layout.php';

        return (string) ob_get_clean();
    }

    /**
     * Render a content-area template to a string.
     *
     * @param array<string, mixed> $data
     */
    private function renderContentTemplate(string $template, array $data): string
    {
        $templatePath = __DIR__ . '/../View/templates/' . $template . '.php';
        extract($data);
        ob_start();
        include $templatePath;

        return (string) ob_get_clean();
    }
}
