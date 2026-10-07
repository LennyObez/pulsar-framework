<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @dev_reload directive for live template reloading.
 *
 * In development mode, injects a WebSocket listener script that auto-refreshes
 * the page when template files change. In production, outputs nothing.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class DevReloadDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'dev_reload';
    }

    public function compile(string $expression): string
    {
        return <<<'PHP'
            <?php if (($__env_mode ?? 'production') !== 'production'): ?>
            <script<?php echo isset($__csp_nonce) ? ' nonce="' . htmlspecialchars($__csp_nonce, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
            (function() {
                var proto = location.protocol === 'https:' ? 'wss:' : 'wss:';
                var port = parseInt(location.port || '8000', 10) + 1;
                var ws = new WebSocket(proto + '//' + location.hostname + ':' + port + '/__pulse/reload');
                ws.onmessage = function(e) {
                    if (e.data === 'reload') location.reload();
                };
                ws.onclose = function() {
                    setTimeout(function() { location.reload(); }, 2000);
                };
            })();
            </script>
            <?php endif; ?>
            PHP;
    }
}
