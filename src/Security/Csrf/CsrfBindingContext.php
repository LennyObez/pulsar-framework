<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Fiber;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Runtime\ResettableInterface;
use stdClass;
use WeakMap;

use function is_string;

/**
 * Per-request holder for the browser CSRF binding secret.
 *
 * {@see CsrfBindingCookieMiddleware} writes the current request's `__Host-`
 * cookie value here; {@see StatelessCsrfManager}'s binding provider reads it at
 * generate/validate time. This is the seam that lets a singleton manager see a
 * request-scoped value without threading the request through the token interface.
 *
 * Storage is keyed by the current Fiber (or a stable root key outside any Fiber)
 * via a WeakMap, so concurrent Fiber-suspended requests on one worker never read
 * each other's binding — the same isolation {@see \Pulsar\Context\RequestContextHolder}
 * uses.
 */
#[Internal]
final class CsrfBindingContext implements ResettableInterface
{
    /** @var WeakMap<object, string> Per-Fiber (or root) binding value. */
    private WeakMap $bindings;

    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, string> $map */
        $map = new WeakMap();
        $this->bindings = $map;
        $this->rootKey = new stdClass();
    }

    public function set(string $binding): void
    {
        $this->bindings[$this->currentKey()] = $binding;
    }

    /**
     * The current request's binding, or '' when none is set — which
     * {@see StatelessCsrfManager} treats as fail-closed.
     */
    public function current(): string
    {
        $binding = $this->bindings[$this->currentKey()] ?? '';

        return is_string($binding) ? $binding : '';
    }

    #[Override]
    public function resetRequestState(): void
    {
        unset($this->bindings[$this->currentKey()]);
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
