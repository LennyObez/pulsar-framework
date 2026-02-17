<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @catch directive to close an error boundary block.
 *
 * Renders the captured content on success, or the fallback message on failure.
 *
 * Usage: @catch('Fallback message')
 * The caught exception is available as $e in the fallback scope.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CatchDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'catch';
    }

    public function compile(string $expression): string
    {
        $fallback = trim($expression);

        if ($fallback === '') {
            $fallback = "''";
        }

        return sprintf(
            '<?php echo ob_get_clean(); } catch (\Throwable $e) { ob_end_clean(); echo htmlspecialchars((string) (%s), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); } ?>',
            $fallback,
        );
    }
}
