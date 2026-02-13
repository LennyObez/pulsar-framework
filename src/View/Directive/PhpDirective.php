<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

/**
 * Compiles the @php directive for inline PHP blocks.
 *
 * Policy-controlled: disabled by default in production.
 * When disabled, compilation fails with a clear error.
 * When enabled, each usage is intended to be audited by the engine.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class PhpDirective implements DirectiveInterface
{
    public function __construct(
        private ViewConfig $config,
    ) {}

    public function name(): string
    {
        return 'php';
    }

    public function compile(string $expression): string
    {
        if (!$this->config->phpDirectiveAllowed) {
            throw ViewException::phpDirectiveDisabled('<compile-time>');
        }

        return '<?php /* @php block */ {';
    }
}
