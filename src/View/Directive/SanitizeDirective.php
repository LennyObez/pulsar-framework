<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @sanitize directive for HTML sanitization.
 *
 * Runs a value through htmlspecialchars with safe defaults for HTML context output.
 * Usage: @sanitize($userHtml)
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class SanitizeDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'sanitize';
    }

    public function compile(string $expression): string
    {
        return sprintf(
            '<?php echo htmlspecialchars((string) (%s), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>',
            trim($expression),
        );
    }
}
