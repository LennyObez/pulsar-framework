<?php

declare(strict_types=1);

namespace Pulsar\View\Sandbox;

use Pulsar\Api\Internal;
use Pulsar\View\ViewException;

/**
 * Sandboxed template engine for untrusted (user-provided) templates.
 *
 * Templates are parsed into an AST and interpreted — NEVER compiled to PHP.
 * Only a restricted subset of directives is available. All output is auto-escaped.
 * Resource bounds prevent runaway execution.
 */
#[Internal(reason: 'Sandbox engine is an implementation detail')]
final class SandboxEngine
{
    private readonly AstParser $parser;

    private readonly AstInterpreter $interpreter;

    public function __construct(
        SandboxConfig $config,
        ?TranslationCallback $translationCallback = null,
    ) {
        $this->parser = new AstParser();
        $this->interpreter = new AstInterpreter($config, $translationCallback);
    }

    /**
     * Render an untrusted template string with the given data.
     *
     * @param string $source Raw template source (untrusted)
     * @param array<string, mixed> $data Template variables
     *
     * @throws ViewException If the template is invalid or exceeds resource limits
     */
    public function render(string $source, array $data = []): string
    {
        $ast = $this->parser->parse($source);

        return $this->interpreter->interpret($ast, $data);
    }
}
