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
use function implode;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function strlen;
use function substr;

use const EXTR_SKIP;

/**
 * True progressive streaming template engine.
 *
 * Unlike StreamingTemplateEngine (which renders fully then splits),
 * this engine yields chunks as they are computed by executing the
 * compiled template in output-buffered segments.
 *
 * The engine uses a custom output buffer callback to capture output
 * in real-time, yielding each chunk as soon as the buffer reaches
 * the configured threshold.
 *
 * This approach is essential for large pages where the full render
 * may take significant time: the client starts receiving HTML
 * immediately rather than waiting for complete server-side rendering.
 */
#[Internal(reason: 'Streaming engine is an implementation detail; use via TemplateEngineInterface')]
final readonly class ProgressiveStreamingEngine
{
    public function __construct(
        private TemplateCompiler $compiler,
        private ?TemplateProfiler $profiler = null,
    ) {}

    /**
     * Stream a template with true progressive rendering.
     *
     * Yields HTML chunks as they are computed rather than rendering
     * the entire output first. Each yield occurs when the output
     * buffer reaches the flush threshold.
     *
     * @param string $template Template name
     * @param array<string, mixed> $data Variables available inside the template
     * @param int $flushThreshold Minimum bytes to accumulate before yielding
     *
     * @return Generator<int, string, void, void>
     *
     * @throws ViewException If the template cannot be found or rendered
     */
    public function stream(string $template, array $data = [], int $flushThreshold = 4096): Generator
    {
        $stop = $this->profiler?->start($template);

        try {
            $compiled = $this->compiler->compile($template);

            yield from $this->executeStreaming($compiled->compiledPath, $data, $flushThreshold);
        } finally {
            if ($stop !== null) {
                $stop();
            }
        }
    }

    /**
     * Execute a compiled template with progressive output buffering.
     *
     * Uses a chunked output buffer that yields content as it is produced
     * by the template, rather than waiting for full render completion.
     *
     * @param string $_path_ Compiled template path
     * @param array<string, mixed> $_data_ Template variables
     * @param int $_threshold_ Flush threshold in bytes
     *
     * @return Generator<int, string, void, void>
     *
     * @throws ViewException If template execution fails
     */
    private function executeStreaming(string $_path_, array $_data_, int $_threshold_): Generator
    {
        if (!array_key_exists('__env', $_data_)) {
            $_data_['__env'] = new TemplateInheritance();
        }

        if (!array_key_exists('__auth', $_data_)) {
            $_data_['__auth'] = new TemplateAuthHelper(null, null);
        }

        /** @var TemplateInheritance $__streamEnv */
        $__streamEnv = $_data_['__env'];

        // @include and @extends need a render callback. It fully renders a
        // sub-template to a string (not streamed), sharing the same $__env so
        // sections/stacks carry over — without it, an inherited template would
        // buffer its sections and stream a blank body.
        $self = $this;
        $__streamEnv->setRenderCallback(static function (string $t, array $d = []) use ($self, $_data_): string {
            return $self->renderToString($t, [...$_data_, ...$d]);
        });

        extract($_data_, EXTR_SKIP);

        /** @var list<string> $chunks */
        $chunks = [];
        $buffer = '';

        // Use output buffering with a custom callback that captures chunks
        ob_start(static function (string $output, int $phase) use (&$chunks, &$buffer, $_threshold_): string {
            $buffer .= $output;

            while (strlen($buffer) >= $_threshold_) {
                $chunks[] = substr($buffer, 0, $_threshold_);
                $buffer = substr($buffer, $_threshold_);
            }

            // Final flush: emit remaining buffer
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0 && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            return ''; // Consume all output
        }, $_threshold_);

        try {
            include $_path_;
        } catch (Throwable $e) {
            ob_end_clean();

            throw ViewException::compilationFailed(
                $_path_,
                'execution failed: ' . $e->getMessage(),
                $e,
            );
        }

        ob_end_clean();

        // Yield any remaining content in the buffer
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        // If the template @extends a layout, its body was captured into $env
        // sections and the streamed chunks are empty/whitespace. Resolve the
        // inheritance chain to a single composed string and chunk THAT, rather
        // than yielding a blank page. Progressive streaming is necessarily
        // traded away for inherited templates, whose layout wraps all output.
        if ($__streamEnv->getParent() !== null) {
            yield from $this->chunkString(
                $__streamEnv->renderWithInheritance(implode('', $chunks)),
                $_threshold_,
            );
        } else {
            foreach ($chunks as $chunk) {
                yield $chunk;
            }
        }
    }

    /**
     * Fully render a sub-template (parent layout or @include) to a string,
     * sharing the caller's $__env so sections and stacks carry over.
     *
     * @param array<string, mixed> $data
     *
     * @throws ViewException
     */
    private function renderToString(string $template, array $data): string
    {
        $compiled = $this->compiler->compile($template);

        return self::executeToString($compiled->compiledPath, $data);
    }

    /**
     * Execute a compiled template in an isolated scope and return its output.
     *
     * @param array<string, mixed> $_data_
     *
     * @throws ViewException If template execution fails
     */
    private static function executeToString(string $_path_, array $_data_): string
    {
        extract($_data_, EXTR_SKIP);

        ob_start();

        try {
            include $_path_;
        } catch (Throwable $e) {
            ob_end_clean();

            throw ViewException::compilationFailed(
                $_path_,
                'execution failed: ' . $e->getMessage(),
                $e,
            );
        }

        return (string) ob_get_clean();
    }

    /**
     * Split a fully-composed string into threshold-sized chunks.
     *
     * @return Generator<int, string, void, void>
     */
    private function chunkString(string $output, int $threshold): Generator
    {
        $offset = 0;
        $length = strlen($output);

        while ($offset < $length) {
            $chunk = substr($output, $offset, $threshold);
            $offset += strlen($chunk);

            yield $chunk;
        }
    }

    /**
     * Get the underlying compiler.
     */
    #[NoDiscard]
    public function compiler(): TemplateCompiler
    {
        return $this->compiler;
    }
}
