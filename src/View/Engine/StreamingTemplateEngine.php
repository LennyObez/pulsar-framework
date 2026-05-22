<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Generator;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewException;
use Throwable;

use function array_key_exists;
use function extract;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function preg_split;
use function strlen;
use function substr;

use const EXTR_SKIP;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Streaming template engine that yields HTML chunks via Generator.
 *
 * Instead of buffering the entire rendered output into a single string,
 * this engine yields chunks progressively. This enables:
 *
 * - Chunked transfer encoding (HTTP/1.1) for faster time-to-first-byte
 * - Memory-efficient rendering of large pages
 * - Progressive rendering with `@defer` block boundaries
 *
 * Wraps the standard TemplateEngine and splits its output at @defer
 * boundaries or into fixed-size chunks.
 */
#[Internal(reason: 'Streaming engine is an implementation detail; use via TemplateEngineInterface')]
final readonly class StreamingTemplateEngine
{
    /** Marker inserted by @defer/@enddefer for stream splitting. */
    private const string DEFER_OPEN_TAG = '<pulse-deferred';

    /** End tag for deferred blocks. */
    private const string DEFER_CLOSE_TAG = '</pulse-deferred>';

    public function __construct(
        private TemplateCompiler $compiler,
        private ?TemplateProfiler $profiler = null,
    ) {}

    /**
     * Stream a template as a Generator yielding HTML chunks.
     *
     * Splits output at @defer block boundaries for progressive rendering.
     * Each chunk is yielded as soon as it is ready.
     *
     * @param string $template Template name
     * @param array<string, mixed> $data Variables available inside the template
     * @param int $chunkSize Minimum bytes per yielded chunk (for non-deferred content)
     *
     * @return Generator<int, string, void, void>
     *
     * @throws ViewException If the template cannot be found or rendered
     */
    public function stream(string $template, array $data = [], int $chunkSize = 4096): Generator
    {
        $stop = $this->profiler?->start($template);

        try {
            $output = $this->renderFull($template, $data);

            yield from $this->splitAtDeferBoundaries($output, $chunkSize);
        } finally {
            if ($stop !== null) {
                $stop();
            }
        }
    }

    /**
     * Stream a template using fixed-size chunks without defer-boundary splitting.
     *
     * Simpler than stream() but does not respect @defer boundaries.
     * Useful when the template has no deferred blocks.
     *
     * @param string $template Template name
     * @param array<string, mixed> $data Variables available inside the template
     * @param int $chunkSize Bytes per chunk
     *
     * @return Generator<int, string, void, void>
     *
     * @throws ViewException If the template cannot be found or rendered
     */
    public function streamFixed(string $template, array $data = [], int $chunkSize = 4096): Generator
    {
        $output = $this->renderFull($template, $data);
        $offset = 0;
        $length = strlen($output);

        while ($offset < $length) {
            $chunk = substr($output, $offset, $chunkSize);
            $offset += strlen($chunk);

            yield $chunk;
        }
    }

    /**
     * Stream a pre-compiled template from its CompiledTemplate artifact.
     *
     * Skips the compilation step for templates that are already compiled.
     * Useful for prewarmed caches.
     *
     * @param CompiledTemplate $compiled Pre-compiled template artifact
     * @param array<string, mixed> $data Variables available inside the template
     * @param int $chunkSize Minimum bytes per yielded chunk
     *
     * @return Generator<int, string, void, void>
     *
     * @throws ViewException If template execution fails
     */
    public function streamCompiled(CompiledTemplate $compiled, array $data = [], int $chunkSize = 4096): Generator
    {
        $output = self::executeIsolated($compiled->compiledPath, $data);

        yield from $this->splitAtDeferBoundaries($output, $chunkSize);
    }

    /**
     * Render the full template output in one pass.
     *
     * @param array<string, mixed> $data
     *
     * @throws ViewException
     */
    private function renderFull(string $template, array $data): string
    {
        if (!array_key_exists('__env', $data)) {
            $env = new TemplateInheritance();
            $data['__env'] = $env;
        } else {
            /** @var TemplateInheritance $env */
            $env = $data['__env'];
        }

        $self = $this;
        $env->setRenderCallback(function (string $t, array $d = []) use ($self, $data): string {
            $mergedData = [...$data, ...$d];

            return $self->renderFull($t, $mergedData);
        });

        if (!array_key_exists('__auth', $data)) {
            $data['__auth'] = new TemplateAuthHelper(null, null);
        }

        $compiled = $this->compiler->compile($template);

        return self::executeIsolated($compiled->compiledPath, $data);
    }

    /**
     * Split rendered output at @defer block boundaries.
     *
     * Yields content before each deferred block immediately, then yields
     * each deferred block as a separate chunk. Non-deferred content between
     * blocks is accumulated up to chunkSize before yielding.
     *
     * @return Generator<int, string, void, void>
     */
    private function splitAtDeferBoundaries(string $output, int $chunkSize): Generator
    {
        // Split on <pulse-deferred...>...</pulse-deferred> boundaries
        $parts = preg_split(
            '/(' . preg_quote(self::DEFER_OPEN_TAG, '/') . '.*?' . preg_quote(self::DEFER_CLOSE_TAG, '/') . ')/s',
            $output,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        if ($parts === false) {
            yield $output;

            return;
        }

        $buffer = '';

        foreach ($parts as $part) {
            $isDeferBlock = str_starts_with($part, self::DEFER_OPEN_TAG);

            if ($isDeferBlock) {
                // Flush any accumulated non-deferred content first
                if ($buffer !== '') {
                    yield $buffer;
                    $buffer = '';
                }

                // Yield the deferred block as its own chunk
                yield $part;
            } else {
                $buffer .= $part;

                // Yield when buffer exceeds chunk size
                while (strlen($buffer) >= $chunkSize) {
                    yield substr($buffer, 0, $chunkSize);
                    $buffer = substr($buffer, $chunkSize);
                }
            }
        }

        // Flush remaining buffer
        if ($buffer !== '') {
            yield $buffer;
        }
    }

    /**
     * Execute a compiled template in an isolated scope.
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

    /**
     * Get the underlying compiler for directive registration.
     */
    #[NoDiscard]
    public function compiler(): TemplateCompiler
    {
        return $this->compiler;
    }
}
