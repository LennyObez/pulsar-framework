<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Pulsar\Api\Api;

use function array_key_exists;

/**
 * Context handed to a view composer for a single render.
 *
 * A composer ({@see TemplateEngineInterface::composer()}) receives the name of
 * the template being rendered and the data resolved so far (shared data plus
 * any earlier composers' output), and contributes additional data in one of
 * two equivalent ways:
 *
 *  - return an associative array from the composer callable, or
 *  - call {@see with()} on this context.
 *
 * Both are merged into the render data beneath the caller's explicit
 * `render()` data (explicit data always wins on a key collision).
 * @api
 */
#[Api(since: '1.0.0')]
final class ViewContext
{
    /** @var array<string, mixed> Data contributed via {@see with()}. */
    private array $added = [];

    /**
     * @param string $template The template name being rendered (dot-notation).
     * @param array<string, mixed> $data The data resolved so far (read-only view):
     *        shared data plus earlier composers' output, before this render's
     *        explicit data is applied.
     */
    public function __construct(
        public readonly string $template,
        private readonly array $data = [],
    ) {}

    /**
     * Read a value from the data resolved so far (shared data + earlier
     * composers). Returns $default when the key is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * Whether a key is present in the data resolved so far.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Contribute a value to the render (alternative to returning an array).
     * Later calls override earlier ones for the same key.
     */
    public function with(string $key, mixed $value): self
    {
        $this->added[$key] = $value;

        return $this;
    }

    /**
     * The data contributed via {@see with()} during this composer invocation.
     *
     * @return array<string, mixed>
     */
    public function added(): array
    {
        return $this->added;
    }
}
