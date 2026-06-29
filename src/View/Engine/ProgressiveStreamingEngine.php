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
            /** @psalm-suppress UnresolvableInclude */
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

        foreach ($chunks as $chunk) {
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
