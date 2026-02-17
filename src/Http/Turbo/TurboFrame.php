<?php

declare(strict_types=1);

namespace Pulsar\Http\Turbo;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Represents a Turbo Frame element for scoped navigation.
 *
 * Turbo Frames decompose pages into independently updateable sections.
 * Navigation within a frame stays within that frame, enabling partial
 * page updates without JavaScript.
 */
#[Api(since: '1.0.0')]
final readonly class TurboFrame
{
    /**
     * @param array<string, string> $attributes Extra HTML attributes
     */
    private function __construct(
        private string $id,
        private string $content,
        private ?string $src,
        private bool $lazy,
        private ?string $target,
        private bool $disabled,
        private array $attributes,
    ) {}

    /**
     * Create a frame with inline content.
     */
    #[NoDiscard]
    public static function create(string $id, string $content = ''): self
    {
        return new self(
            id: $id,
            content: $content,
            src: null,
            lazy: false,
            target: null,
            disabled: false,
            attributes: [],
        );
    }

    /**
     * Create a lazy-loading frame that fetches content from a URL.
     */
    #[NoDiscard]
    public static function lazy(string $id, string $src, string $placeholder = ''): self
    {
        return new self(
            id: $id,
            content: $placeholder,
            src: $src,
            lazy: true,
            target: null,
            disabled: false,
            attributes: [],
        );
    }

    /**
     * Set a target frame for link/form navigation.
     *
     * Use '_top' to break out of the frame.
     */
    #[NoDiscard]
    public function withTarget(string $target): self
    {
        return clone($this, ['target' => $target]);
    }

    /**
     * Disable the frame (prevents navigation within it).
     */
    #[NoDiscard]
    public function withDisabled(bool $disabled = true): self
    {
        return clone($this, ['disabled' => $disabled]);
    }

    /**
     * Add a custom HTML attribute to the frame element.
     */
    #[NoDiscard]
    public function withAttribute(string $name, string $value): self
    {
        $attrs = $this->attributes;
        $attrs[$name] = $value;

        return clone($this, ['attributes' => $attrs]);
    }

    /**
     * Render the frame as an HTML element.
     */
    #[NoDiscard]
    public function toHtml(): string
    {
        $escapedId = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
        $attrs = sprintf('id="%s"', $escapedId);

        if ($this->src !== null) {
            $attrs .= sprintf(' src="%s"', htmlspecialchars($this->src, ENT_QUOTES, 'UTF-8'));
        }

        if ($this->lazy) {
            $attrs .= ' loading="lazy"';
        }

        if ($this->target !== null) {
            $attrs .= sprintf(' target="%s"', htmlspecialchars($this->target, ENT_QUOTES, 'UTF-8'));
        }

        if ($this->disabled) {
            $attrs .= ' disabled';
        }

        foreach ($this->attributes as $name => $value) {
            $attrs .= sprintf(
                ' %s="%s"',
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            );
        }

        return sprintf('<pulsar-frame %s>%s</pulsar-frame>', $attrs, $this->content);
    }

    public function id(): string
    {
        return $this->id;
    }
}
