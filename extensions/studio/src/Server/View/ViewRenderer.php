<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\View;

use const EXTR_SKIP;

use function extract;
use function file_exists;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;

use Pulsar\Api\Internal;
use RuntimeException;

use function sprintf;

use Throwable;

/**
 * Simple PHP template renderer for Studio views.
 *
 * Renders PHP templates with extracted variables.
 * Templates are plain PHP files that output HTML.
 */
#[Internal]
final readonly class ViewRenderer
{
    public function __construct(
        private string $templateDir,
    ) {}

    /**
     * Render a template with the given data.
     *
     * @param string $template Template name (e.g., 'console/overview')
     * @param array<string, mixed> $data Variables to extract into template scope
     *
     * @throws RuntimeException If template not found
     * @throws Throwable If template execution fails
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->templateDir . '/' . $template . '.php';

        if (!file_exists($path)) {
            throw new RuntimeException(sprintf('Template not found: %s', $path));
        }

        return self::renderIsolated($path, $data);
    }

    /**
     * Render a template and wrap it in the layout.
     *
     * @param string $template Template name
     * @param array<string, mixed> $data Variables for the template
     * @param string $title Page title
     * @throws RuntimeException If template not found
     * @throws Throwable If template execution fails
     */
    public function renderWithLayout(string $template, array $data = [], string $title = 'Pulsar Studio'): string
    {
        $content = $this->render($template, $data);

        return $this->render('layout', [
            'title' => $title,
            'content' => $content,
        ]);
    }

    /**
     * Render in an isolated scope so extract() does not pollute the caller.
     *
     * Uses a static method to avoid per-call closure allocation.
     * Double-underscore suffix avoids collision with template variables.
     *
     * @param array<string, mixed> $_data_
     */
    private static function renderIsolated(string $_path_, array $_data_): string
    {
        extract($_data_, EXTR_SKIP);

        ob_start();

        try {
            /** @psalm-suppress UnresolvableInclude */
            include $_path_;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
