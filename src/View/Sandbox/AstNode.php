<?php

declare(strict_types=1);

namespace Pulsar\View\Sandbox;

use Pulsar\Api\Internal;

/**
 * Represents a node in the template AST for untrusted template execution.
 *
 * The AST is walked by the interpreter: never compiled to PHP.
 */
#[Internal(reason: 'AST internals are an engine implementation detail')]
final readonly class AstNode
{
    /**
     * @param AstNodeType $type Node type
     * @param string $value Raw value (text content, variable name, directive expression)
     * @param list<AstNode> $children Child nodes (block body for directives)
     * @param list<AstNode> $elseChildren Else branch nodes (for @if/@else)
     * @param string $tag Directive tag name (e.g., 'if', 'foreach')
     */
    public function __construct(
        public AstNodeType $type,
        public string $value = '',
        public array $children = [],
        public array $elseChildren = [],
        public string $tag = '',
    ) {}
}
