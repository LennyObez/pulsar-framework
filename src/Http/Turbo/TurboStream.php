<?php

declare(strict_types=1);

namespace Pulsar\Http\Turbo;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Represents a single Turbo Stream element.
 *
 * Turbo Streams deliver HTML updates as custom elements that the
 * client processes to modify the DOM. Each stream targets an element
 * by ID and applies an action (append, replace, remove, etc.).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TurboStream
{
    private function __construct(
        private TurboStreamAction $action,
        private string $target,
        private string $html,
    ) {}

    /**
     * Append HTML content inside the target element.
     */
    #[NoDiscard]
    public static function append(string $target, string $html): self
    {
        return new self(TurboStreamAction::Append, $target, $html);
    }

    /**
     * Prepend HTML content inside the target element.
     */
    #[NoDiscard]
    public static function prepend(string $target, string $html): self
    {
        return new self(TurboStreamAction::Prepend, $target, $html);
    }

    /**
     * Replace the target element entirely.
     */
    #[NoDiscard]
    public static function replace(string $target, string $html): self
    {
        return new self(TurboStreamAction::Replace, $target, $html);
    }

    /**
     * Update the inner HTML of the target element.
     */
    #[NoDiscard]
    public static function update(string $target, string $html): self
    {
        return new self(TurboStreamAction::Update, $target, $html);
    }

    /**
     * Remove the target element from the DOM.
     */
    #[NoDiscard]
    public static function remove(string $target): self
    {
        return new self(TurboStreamAction::Remove, $target, '');
    }

    /**
     * Insert HTML before the target element.
     */
    #[NoDiscard]
    public static function before(string $target, string $html): self
    {
        return new self(TurboStreamAction::Before, $target, $html);
    }

    /**
     * Insert HTML after the target element.
     */
    #[NoDiscard]
    public static function after(string $target, string $html): self
    {
        return new self(TurboStreamAction::After, $target, $html);
    }

    /**
     * Render this stream as a <turbo-stream> HTML element.
     */
    #[NoDiscard]
    public function toHtml(): string
    {
        $escapedTarget = htmlspecialchars($this->target, ENT_QUOTES, 'UTF-8');

        if ($this->action === TurboStreamAction::Remove) {
            return sprintf(
                '<pulsar-stream action="%s" target="%s"></pulsar-stream>',
                $this->action->value,
                $escapedTarget,
            );
        }

        return sprintf(
            '<pulsar-stream action="%s" target="%s"><template>%s</template></pulsar-stream>',
            $this->action->value,
            $escapedTarget,
            $this->html,
        );
    }

    public function action(): TurboStreamAction
    {
        return $this->action;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function html(): string
    {
        return $this->html;
    }
}
