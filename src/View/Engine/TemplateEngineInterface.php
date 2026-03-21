<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Pulsar\Api\Api;
use Pulsar\View\ViewException;

/**
 * Core contract for the Pulsar template engine.
 *
 * Implementations handle template rendering, compilation, and existence checks.
 * @api
 */
#[Api(since: '1.0.0')]
interface TemplateEngineInterface
{
    /**
     * Render a template with the given data and return the output string.
     *
     * @param string $template Template name (dot-notation or path relative to template dirs)
     * @param array<string, mixed> $data Variables available inside the template
     *
     * @throws ViewException If the template cannot be found or rendered
     */
    public function render(string $template, array $data = []): string;

    /**
     * Compile a template and return the compiled artifact.
     *
     * @param string $template Template name
     *
     * @throws ViewException If the template cannot be found or compiled
     */
    public function compile(string $template): CompiledTemplate;

    /**
     * Check whether a template exists in the configured search paths.
     *
     * @param string $template Template name
     */
    public function exists(string $template): bool;
}
