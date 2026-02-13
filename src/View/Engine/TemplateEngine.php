<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Pulsar\Api\Internal;
use Pulsar\View\ViewException;
use Throwable;

use function extract;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;

use const EXTR_SKIP;

/**
 * Compile-to-PHP template engine for trusted templates.
 *
 * Compiles `.pulsar.php` templates to cached PHP files, then executes them
 * with extracted data variables. All output is HTML-escaped by default.
 */
#[Internal(reason: 'Engine implementation detail; use TemplateEngineInterface')]
final class TemplateEngine implements TemplateEngineInterface
{
    public function __construct(
        private readonly TemplateCompiler $compiler,
    ) {}

    /**
     * Access the underlying compiler for directive registration and direct compilation.
     */
    public function compiler(): TemplateCompiler
    {
        return $this->compiler;
    }

    public function render(string $template, array $data = []): string
    {
        $compiled = $this->compiler->compile($template);

        return self::executeIsolated($compiled->compiledPath, $data);
    }

    public function compile(string $template): CompiledTemplate
    {
        return $this->compiler->compile($template);
    }

    public function exists(string $template): bool
    {
        return $this->compiler->exists($template);
    }

    /**
     * Execute a compiled template in an isolated scope.
     *
     * Uses a static method to avoid per-call closure allocation.
     * Underscore-suffixed parameter names avoid collision with extracted template variables.
     *
     * @param array<string, mixed> $_data_
     *
     * @throws ViewException If template execution fails
     */
    private static function executeIsolated(string $_path_, array $_data_): string
    {
        extract($_data_, EXTR_SKIP);

        ob_start();

        try {
            /** @psalm-suppress UnresolvableInclude */
            include $_path_;
        } catch (Throwable $e) {
            ob_end_clean();

            throw ViewException::compilationFailed(
                $_path_,
                'execution failed: ' . $e->getMessage(),
            );
        }

        return (string) ob_get_clean();
    }
}
