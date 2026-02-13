<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function array_key_exists;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_file;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Compiles `.pulsar.php` templates to cached PHP for trusted template execution.
 *
 * Compilation is deterministic: identical source input always produces identical
 * compiled output, with no timestamps, PIDs, or non-deterministic elements.
 *
 * Supports extensible directive compilation via a callback registry.
 */
#[Internal(reason: 'Compiler internals are not part of the public API')]
final class TemplateCompiler
{
    /** @var array<string, callable(string): string> */
    private array $directiveCompilers = [];

    public function __construct(
        private readonly ViewConfig $config,
        private readonly TemplateCache $cache,
    ) {}

    /**
     * Compile a template by name and return the compiled artifact.
     *
     * In development mode, recompiles when source file mtime or content hash changes.
     * In production, uses cached version if available.
     *
     * @param string $templateName Template name (dot-notation or slash-separated)
     *
     * @throws ViewException If the template cannot be found or compiled
     */
    public function compile(string $templateName): CompiledTemplate
    {
        $sourcePath = $this->resolve($templateName);
        $sourceContent = file_get_contents($sourcePath);

        if ($sourceContent === false) {
            throw ViewException::compilationFailed($templateName, 'could not read source file');
        }

        $cached = $this->cache->get($templateName, $sourceContent);

        if ($cached !== null) {
            return $cached;
        }

        $compiledOutput = $this->compileSource($sourceContent, $templateName);

        return $this->cache->put($templateName, $sourceContent, $compiledOutput);
    }

    /**
     * Compile a template source string to PHP output.
     *
     * @param string $source Raw template source
     * @param string $templateName Template name for error context
     */
    #[NoDiscard]
    public function compileSource(string $source, string $templateName = '<inline>'): string
    {
        $output = $source;

        // Phase 1: Strip comment blocks {{-- comment --}} (before echo compilation)
        $output = $this->compileComments($output);

        // Phase 2: Compile directives (registered by the directive system)
        $output = $this->compileDirectives($output, $templateName);

        // Phase 3: Compile raw (unescaped) output {!! $expr !!}
        $output = $this->compileRawEchos($output);

        // Phase 4: Compile escaped output {{ $expr }}
        $output = $this->compileEscapedEchos($output);

        return $output;
    }

    /**
     * Register a directive compiler.
     *
     * @param string $name Directive name (without @)
     * @param callable(string): string $compiler Callable that receives the directive expression and returns PHP code
     */
    public function registerDirective(string $name, callable $compiler): void
    {
        $this->directiveCompilers[$name] = $compiler;
    }

    /**
     * Check whether a directive compiler is registered.
     */
    #[NoDiscard]
    public function hasDirective(string $name): bool
    {
        return array_key_exists($name, $this->directiveCompilers);
    }

    /**
     * Resolve a template name to an absolute filesystem path.
     *
     * @throws ViewException If the template cannot be found in any configured path
     */
    #[NoDiscard]
    public function resolve(string $templateName): string
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $templateName) . '.pulsar.php';

        foreach ($this->config->templatePaths as $basePath) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $relativePath;

            if (is_file($fullPath) && file_exists($fullPath)) {
                return $fullPath;
            }
        }

        $searchedPaths = implode(', ', $this->config->templatePaths);

        throw ViewException::templateNotFound($templateName, $searchedPaths);
    }

    /**
     * Check whether a template exists in the configured search paths.
     */
    #[NoDiscard]
    public function exists(string $templateName): bool
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $templateName) . '.pulsar.php';

        foreach ($this->config->templatePaths as $basePath) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $relativePath;

            if (is_file($fullPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a template needs recompilation based on mtime and content hash.
     *
     * Returns true if the template source has changed since last compilation.
     */
    #[NoDiscard]
    public function needsRecompilation(string $templateName): bool
    {
        $sourcePath = $this->resolve($templateName);
        $sourceContent = file_get_contents($sourcePath);

        if ($sourceContent === false) {
            return true;
        }

        return !$this->cache->has($templateName, $sourceContent);
    }

    /**
     * Compile escaped output expressions: {{ $expr }}
     *
     * Compiles to htmlspecialchars() with ENT_QUOTES | ENT_SUBSTITUTE and UTF-8.
     */
    private function compileEscapedEchos(string $source): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            static fn(array $matches): string => '<?php echo htmlspecialchars((string) (' . trim($matches[1]) . '), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>',
            $source,
        );
    }

    /**
     * Compile raw (unescaped) output expressions: {!! $expr !!}
     */
    private function compileRawEchos(string $source): string
    {
        return (string) preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            static fn(array $matches): string => '<?php echo ' . trim($matches[1]) . '; ?>',
            $source,
        );
    }

    /**
     * Compile template comments: {{-- comment --}}
     *
     * Comments are stripped entirely from the compiled output.
     */
    private function compileComments(string $source): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    /**
     * Compile all registered directives.
     *
     * Matches patterns like @directiveName, @directiveName(...), and block
     * directives like @enddirectiveName.
     */
    private function compileDirectives(string $source, string $templateName): string
    {
        if ($this->directiveCompilers === []) {
            return $source;
        }

        return (string) preg_replace_callback(
            '/@(\w+)(?:\s*\((.*?)\))?/s',
            function (array $matches): string {
                $name = $matches[1];
                $expression = $matches[2] ?? '';

                if (!array_key_exists($name, $this->directiveCompilers)) {
                    return $matches[0];
                }

                return ($this->directiveCompilers[$name])($expression);
            },
            $source,
        );
    }
}
