<?php

declare(strict_types=1);

namespace Pulsar\Rendering\Storybook;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Ui\Embeddable\EmbeddableComponent;
use Throwable;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Renders component stories into a browsable HTML page.
 *
 * Produces a standalone page with component previews, a category
 * sidebar, responsive viewport toggles, and dark mode support.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StorybookRenderer
{
    public function __construct(
        private StorybookRegistry $registry,
    ) {}

    /**
     * Render the full storybook index page.
     */
    #[NoDiscard]
    public function renderIndex(): string
    {
        $categories = $this->registry->categories();

        $sidebar = '<nav class="sb-sidebar" role="navigation" aria-label="Component categories"><ul>';
        foreach ($categories as $category) {
            $escapedCategory = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
            $stories = $this->registry->forCategory($category);
            $sidebar .= sprintf('<li><strong>%s</strong><ul>', $escapedCategory);
            foreach ($stories as $story) {
                $escapedName = htmlspecialchars($story->name, ENT_QUOTES, 'UTF-8');
                $sidebar .= sprintf(
                    '<li><a href="#%s">%s</a></li>',
                    $escapedName,
                    $escapedName,
                );
            }
            $sidebar .= '</ul></li>';
        }
        $sidebar .= '</ul></nav>';

        $content = '<main class="sb-content" role="main">';
        foreach ($this->registry->flat() as $story) {
            $content .= $this->renderStoryCard($story);
        }
        $content .= '</main>';

        return $this->wrapInLayout($sidebar . $content);
    }

    /**
     * Render a single story in isolation.
     */
    #[NoDiscard]
    public function renderStory(ComponentStory $story): string
    {
        return $this->wrapInLayout(
            '<main class="sb-content sb-isolated" role="main">'
            . $this->renderStoryCard($story)
            . '</main>',
        );
    }

    private function renderStoryCard(ComponentStory $story): string
    {
        $escapedName = htmlspecialchars($story->name, ENT_QUOTES, 'UTF-8');
        $escapedDesc = htmlspecialchars($story->description, ENT_QUOTES, 'UTF-8');
        $escapedClass = htmlspecialchars($story->componentClass, ENT_QUOTES, 'UTF-8');

        $preview = $this->renderComponentPreview($story);

        return sprintf(
            '<section class="sb-story" id="%s" aria-label="%s">'
            . '<h2 class="sb-story-title">%s</h2>'
            . '<p class="sb-story-desc">%s</p>'
            . '<p class="sb-story-class"><code>%s</code></p>'
            . '<div class="sb-preview">%s</div>'
            . '</section>',
            $escapedName,
            $escapedName,
            $escapedName,
            $escapedDesc,
            $escapedClass,
            $preview,
        );
    }

    private function renderComponentPreview(ComponentStory $story): string
    {
        if (!class_exists($story->componentClass)) {
            return '<p class="sb-error">Component class not found</p>';
        }

        try {
            $component = new ($story->componentClass)();

            if ($component instanceof EmbeddableComponent) {
                /** @var mixed $value */
                foreach ($story->props as $key => $value) {
                    $component->prop($key, $value);
                }

                return $component->render();
            }

            return '<p class="sb-info">Non-embeddable component: render manually</p>';
        } catch (Throwable $e) {
            return sprintf(
                '<p class="sb-error">Render error: %s</p>',
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            );
        }
    }

    private function wrapInLayout(string $body): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Pulsar Component Storybook</title>
                <style>
                    :root { --bg: #fff; --fg: #1a1a1a; --border: #e5e7eb; --accent: #4f46e5; }
                    @media (prefers-color-scheme: dark) {
                        :root { --bg: #1a1a1a; --fg: #f5f5f5; --border: #333; --accent: #818cf8; }
                    }
                    * { box-sizing: border-box; margin: 0; padding: 0; }
                    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--fg); display: flex; min-height: 100vh; }
                    .sb-sidebar { width: 260px; border-right: 1px solid var(--border); padding: 1rem; overflow-y: auto; }
                    .sb-sidebar ul { list-style: none; }
                    .sb-sidebar a { color: var(--accent); text-decoration: none; display: block; padding: 0.25rem 0; }
                    .sb-content { flex: 1; padding: 2rem; overflow-y: auto; }
                    .sb-story { border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; }
                    .sb-story-title { margin-bottom: 0.5rem; }
                    .sb-story-desc { color: #6b7280; margin-bottom: 0.5rem; }
                    .sb-story-class { margin-bottom: 1rem; }
                    .sb-story-class code { background: var(--border); padding: 0.2rem 0.4rem; border-radius: 4px; font-size: 0.85rem; }
                    .sb-preview { border: 1px dashed var(--border); border-radius: 4px; padding: 1rem; }
                    .sb-error { color: #dc2626; }
                    .sb-info { color: #6b7280; font-style: italic; }
                </style>
            </head>
            <body>
                {$body}
            </body>
            </html>
            HTML;
    }
}
