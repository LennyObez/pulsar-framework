<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Api;

/**
 * Contract for template directives.
 *
 * Each directive compiles its expression to PHP code. The compiled output
 * replaces the directive in the template source during the compilation phase.
 * @api
 */
#[Api(since: '1.0.0')]
interface DirectiveInterface
{
    /**
     * Get the directive name (without the @ prefix).
     */
    public function name(): string;

    /**
     * Compile the directive expression to PHP code.
     *
     * @param string $expression The expression inside the directive parentheses (may be empty)
     *
     * @return string The compiled PHP code
     */
    public function compile(string $expression): string;
}
