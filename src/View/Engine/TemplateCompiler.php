<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function array_key_exists;
use function ctype_alpha;
use function explode;
use function file_get_contents;
use function implode;
use function is_file;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
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
        return $this->compileEscapedEchos($output);
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
        $relativePath = $this->toRelativePath($templateName);

        foreach ($this->config->templatePaths as $basePath) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $relativePath;

            if (is_file($fullPath)) {
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
        $relativePath = $this->toRelativePath($templateName);

        foreach ($this->config->templatePaths as $basePath) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $relativePath;

            if (is_file($fullPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a dot-notation template name to a relative filesystem path.
     *
     * Strips namespace prefixes (e.g., 'cms::admin.layout' → 'admin/layout.pulsar.php').
     */
    private function toRelativePath(string $templateName): string
    {
        if (str_contains($templateName, '::')) {
            $parts = explode('::', $templateName, 2);
            $templateName = $parts[1] ?? $templateName;
        }

        return str_replace('.', DIRECTORY_SEPARATOR, $templateName) . '.pulsar.php';
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
            '/\{\{\s*(.+?)\s*}}/s',
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
            '/\{!!\s*(.+?)\s*!!}/s',
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
        return (string) preg_replace('/\{\{--.*?--}}/s', '', $source);
    }

    /**
     * Compile all registered directives.
     *
     * Matches patterns like @directiveName, @directiveName(...), and block
     * directives like @enddirectiveName. Uses balanced-parenthesis matching
     * so that expressions with nested parens (e.g. `@foreach (($a ?? []) as $v)`)
     * are captured correctly.
     */
    private function compileDirectives(string $source, string $templateName): string
    {
        if ($this->directiveCompilers === []) {
            return $source;
        }

        $result = '';
        $offset = 0;
        $len = strlen($source);

        while ($offset < $len) {
            // Find the next @ that starts a word
            $atPos = strpos($source, '@', $offset);

            if ($atPos === false) {
                $result .= substr($source, $offset);

                break;
            }

            // Copy everything before the @
            $result .= substr($source, $offset, $atPos - $offset);

            // Extract directive name
            $nameStart = $atPos + 1;
            $nameEnd = $nameStart;

            while ($nameEnd < $len && ctype_alpha($source[$nameEnd])) {
                $nameEnd++;
            }

            $name = substr($source, $nameStart, $nameEnd - $nameStart);

            // Not a registered directive — emit as-is and advance past the @name
            if ($name === '' || !array_key_exists($name, $this->directiveCompilers)) {
                $result .= substr($source, $atPos, $nameEnd - $atPos);
                $offset = $nameEnd;

                continue;
            }

            // Check for optional parenthesized expression after the directive name
            $cursor = $nameEnd;

            // Skip whitespace between directive name and opening paren
            while ($cursor < $len && ($source[$cursor] === ' ' || $source[$cursor] === "\t")) {
                $cursor++;
            }

            if ($cursor < $len && $source[$cursor] === '(') {
                // Extract balanced expression
                $expression = $this->extractBalancedExpression($source, $cursor);
                $closePos = $cursor + strlen($expression) + 2; // past '(' + expression + ')'
                $compiled = ($this->directiveCompilers[$name])(trim($expression));
                $result .= $compiled;
                $offset = $closePos;
            } else {
                // No parentheses — e.g. @else, @endif, @endforeach
                $compiled = ($this->directiveCompilers[$name])('');
                $result .= $compiled;
                $offset = $nameEnd;
            }
        }

        return $result;
    }

    /**
     * Extract a balanced-parenthesis expression starting at $openPos (which must point to '(').
     *
     * Returns the content between the outer parentheses (exclusive).
     */
    private function extractBalancedExpression(string $source, int $openPos): string
    {
        $depth = 0;
        $len = strlen($source);

        for ($i = $openPos; $i < $len; $i++) {
            $char = $source[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $openPos + 1, $i - $openPos - 1);
                }
            }
        }

        // Unbalanced — return everything after the opening paren
        return substr($source, $openPos + 1);
    }
}
