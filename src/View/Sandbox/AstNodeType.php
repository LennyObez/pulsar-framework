<?php

declare(strict_types=1);

namespace Pulsar\View\Sandbox;

use Pulsar\Api\Internal;

/**
 * Types of AST nodes in the untrusted template parser.
 */
#[Internal(reason: 'AST internals are an engine implementation detail')]
enum AstNodeType: string
{
    /** Static text content */
    case Text = 'text';

    /** Escaped output: {{ $var }} */
    case Output = 'output';

    /** @if / @elseif / @else / @endif block */
    case If = 'if';

    /** @foreach / @endforeach block */
    case Foreach = 'foreach';

    /** Include directive (template ID only) */
    case Include = 'include';

    /** @i18n (translation) */
    case I18n = 'i18n';

    /** Root container */
    case Root = 'root';
}
