<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Generator;
use Pulsar\Api\Internal;
use Pulsar\View\ViewException;
use Throwable;

use function array_key_exists;
use function extract;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function strlen;

use const EXTR_SKIP;

/**
 * Compile-to-PHP template engine for trusted templates.
 *
 * Compiles `.pulse.php` templates to cached PHP files, then executes them
 * with extracted data variables. All output is HTML-escaped by default.
 *
 * Runtime helpers (`$__env` for inheritance, `$__auth` for authorization)
 * are injected automatically unless the caller provides them in the data array.
 */
#[Internal(reason: 'Engine implementation detail; use TemplateEngineInterface')]
final readonly class TemplateEngine implements TemplateEngineInterface
{
    public function __construct(
        private TemplateCompiler $compiler,
    ) {}

    /**
     * Access the underlying compiler for directive registration and direct compilation.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function compiler(): TemplateCompiler
    {
        return $this->compiler;
    }

    public function render(string $template, array $data = []): string
    {
        // Check if $__env was supplied externally (e.g. by @extends parent rendering)
        $isTopLevel = !array_key_exists('__env', $data);

        if ($isTopLevel) {
            $env = new TemplateInheritance();
            $data['__env'] = $env;
        } else {
            /** @var TemplateInheritance $env */
            $env = $data['__env'];
        }

        // The render callback shares the same $env so sections defined in the child
        // are visible when the parent template calls @yield.
        $env->setRenderCallback(function (string $t, array $d = []) use ($data): string {
            // Merge caller data + sub-template data, but always carry __env and __auth
            $mergedData = [...$data, ...$d];

            return $this->render($t, $mergedData);
        });

        // Provide $__auth (authorization helper) unless caller supplied one
        if (!array_key_exists('__auth', $data)) {
            $data['__auth'] = new TemplateAuthHelper(null, null);
        }

        $compiled = $this->compiler->compile($template);
        $output = self::executeIsolated($compiled->compiledPath, $data);

        // Only the top-level render resolves @extends inheritance
        if ($isTopLevel) {
            return $env->renderWithInheritance($output);
        }

        return $output;
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
     * Stream a template as a Generator that yields HTML chunks.
     *
     * Compatible with StreamedResponse for chunked transfer encoding.
     * Splits output at `@defer` boundaries for progressive rendering.
     *
     * @param string $template Template name
     * @param array<string, mixed> $data Variables available inside the template
     * @param int $chunkSize Minimum bytes per yielded chunk
     *
     * @return Generator<int, string, void, void>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function stream(string $template, array $data = [], int $chunkSize = 4096): Generator
    {
        $output = $this->render($template, $data);
        $offset = 0;
        $length = strlen($output);

        while ($offset < $length) {
            $chunk = substr($output, $offset, $chunkSize);
            $offset += strlen($chunk);

            yield $chunk;
        }
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
