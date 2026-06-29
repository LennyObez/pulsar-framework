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

    /**
     * Share data with every subsequent render performed by this engine —
     * including framework-internal renders (error pages, mails, ...).
     *
     * Shared data is request-scoped (it never leaks across requests or Fibers)
     * and is merged beneath each render's explicit data: on a key collision the
     * explicit {@see render()} `$data` wins. For application-lifetime constants,
     * register a `composer('*', ...)` instead.
     *
     * @param string|array<string, mixed> $key A single key, or an associative
     *        array of key => value pairs for bulk sharing.
     */
    public function share(string|array $key, mixed $value = null): void;

    /**
     * Register a view composer: a callable invoked lazily for every render
     * whose template name matches any of the given glob patterns (e.g.
     * 'theme.partials.*', 'errors.*', '*').
     *
     * The composer receives a {@see ViewContext} and contributes data either by
     * returning an associative array or by calling {@see ViewContext::with()}.
     * It runs at most once per request (its output is memoized the first time a
     * pattern matches), so expensive shared data is built once even when several
     * partials match. Pattern matching itself runs on every render; only the
     * composer's execution is memoized. Multiple matching composers run in
     * registration order (later may override earlier). Precedence, low to high:
     * shared data → composer output → explicit render() data.
     *
     * Nested renders (`@include` partials) inherit the parent render's resolved
     * data as their explicit data. On a key collision that inherited value
     * therefore wins: a partial-matching composer cannot override a key the
     * parent render already resolved (e.g. from a wildcard composer) — it can
     * only add keys the parent did not provide.
     *
     * Register composers at boot (after the engine is bound, before the first
     * render) so they also apply to early/error-path renders.
     *
     * @param string|list<string> $patterns
     * @param callable(ViewContext): (array<string, mixed>|null|void) $composer
     */
    public function composer(string|array $patterns, callable $composer): void;
}
