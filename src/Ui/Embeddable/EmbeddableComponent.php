<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function json_encode;
use function sprintf;

use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Base class for Pulsar embeddable UI components.
 *
 * Each component renders as a custom element (<pulsar-*>) that is
 * self-contained, styled, accessible, and works standalone or within
 * Pulsar Live components.
 */
#[Api(since: '1.0.0')]
abstract class EmbeddableComponent
{
    /** @var array<string, mixed> */
    protected array $props = [];

    /** @var array<string, string> */
    protected array $attributes = [];

    /**
     * The custom element tag name (e.g. 'pulsar-data-table').
     */
    abstract public function tagName(): string;

    /**
     * Render the component's inner HTML.
     */
    abstract public function renderInner(): string;

    /**
     * Set a component prop.
     */
    public function prop(string $name, mixed $value): static
    {
        $this->props[$name] = $value;

        return $this;
    }

    /**
     * Set an HTML attribute on the custom element.
     */
    public function attr(string $name, string $value): static
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    /**
     * Render the complete custom element HTML.
     */
    #[NoDiscard]
    public function render(): string
    {
        $inner = $this->renderInner();

        $attrs = '';

        foreach ($this->attributes as $name => $value) {
            $attrs .= sprintf(
                ' %s="%s"',
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            );
        }

        if ($this->props !== []) {
            $propsJson = htmlspecialchars(
                json_encode($this->props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ENT_QUOTES,
                'UTF-8',
            );
            $attrs .= sprintf(' data-props="%s"', $propsJson);
        }

        return sprintf(
            '<%s%s>%s</%s>',
            $this->tagName(),
            $attrs,
            $inner,
            $this->tagName(),
        );
    }

    /**
     * Get the component's CSS for embedding.
     */
    public function styles(): string
    {
        return '';
    }

    /**
     * Get the component's JavaScript for client-side behavior.
     */
    public function script(): string
    {
        return '';
    }
}
