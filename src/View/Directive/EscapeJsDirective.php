<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @escape_js directive for safe JavaScript embedding.
 *
 * JSON-encodes the given value with HTML-safe flags for secure inline script output.
 * Usage: @escape_js($data)
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class EscapeJsDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'escape_js';
    }

    public function compile(string $expression): string
    {
        return sprintf(
            '<?php echo json_encode(%s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?>',
            trim($expression),
        );
    }
}
