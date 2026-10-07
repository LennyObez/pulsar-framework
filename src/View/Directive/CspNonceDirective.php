<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @csp_nonce directive to output the current request's CSP nonce.
 *
 * Outputs a nonce attribute value from the `$__csp_nonce` variable injected
 * by the security middleware. If no nonce is available, outputs an empty string.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CspNonceDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'csp_nonce';
    }

    public function compile(string $expression): string
    {
        return '<?php echo htmlspecialchars($__csp_nonce ?? \'\', ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>';
    }
}
