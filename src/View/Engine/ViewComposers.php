<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Closure;
use Fiber;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Runtime\ResettableInterface;
use stdClass;
use WeakMap;

use function array_key_exists;
use function array_values;
use function fnmatch;
use function is_array;
use function is_string;

/**
 * Shared view data and view composers applied to every {@see TemplateEngine}
 * render — including framework-internal renders such as error pages.
 *
 * Two tiers:
 *  - Global (app-lifetime): composer registrations, set once at boot. These are
 *    pure configuration (a pattern list + a callable); reading them concurrently
 *    from several Fibers is safe. Register them before serving the first request
 *    (composer() is not intended to be called per-request).
 *  - Request (per-Fiber, resettable): shared data set via {@see share()} and the
 *    memoized output of each composer. Stored in a {@see WeakMap} keyed by the
 *    current Fiber (or a stable root key outside any Fiber), exactly like
 *    {@see \Pulsar\Context\RequestContextHolder}, so persistent-worker runtimes
 *    (RoadRunner / FrankenPHP / Swoole) that interleave Fiber-suspended requests
 *    never bleed one request's view data into another. {@see resetRequestState()}
 *    additionally clears the slot between sequential non-Fibered requests.
 *
 * Precedence on a key collision, low to high: shared data → composer output
 * (later composers override earlier) → the caller's explicit render() data.
 *
 * Each registered composer runs at most once per request: its output is
 * memoized the first time any of its patterns matches a rendered template, so
 * expensive shared data (e.g. a localized navigation tree) is built once even
 * when several partials match in the same request.
 */
#[Internal]
final class ViewComposers implements ResettableInterface
{
    /**
     * Stored with a `mixed` return so the public contract can admit `void`
     * composers (a void-typed call result cannot be consumed); the invocation
     * normalises the result through {@see stringKeyed()}.
     *
     * @var list<array{patterns: list<string>, composer: Closure(ViewContext): mixed}>
     */
    private array $composers = [];

    /** @var WeakMap<object, array{shared: array<string, mixed>, memo: array<int, array<string, mixed>>}> */
    private WeakMap $requestState;

    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, array{shared: array<string, mixed>, memo: array<int, array<string, mixed>>}> $map */
        $map = new WeakMap();
        $this->requestState = $map;
        $this->rootKey = new stdClass();
    }

    /**
     * Share data with every subsequent render in the current request.
     *
     * Request-scoped: a value shared in one request is never visible to another
     * (no cross-request or cross-Fiber bleed). For application-lifetime
     * constants, register a `composer('*', ...)` instead.
     *
     * @param string|array<string, mixed> $key A single key, or an associative
     *        array of key => value pairs for bulk sharing.
     */
    public function share(string|array $key, mixed $value = null): void
    {
        $pairs = is_array($key) ? $key : [$key => $value];

        $stateKey = $this->currentKey();
        $state = $this->requestState[$stateKey] ?? null;
        if ($state === null) {
            $state = ['shared' => [], 'memo' => []];
        }

        foreach ($pairs as $name => $shared) {
            $state['shared'][$name] = $shared;
        }

        $this->requestState[$stateKey] = $state;
    }

    /**
     * Register a composer invoked for renders whose template name matches any
     * of the given glob patterns (e.g. 'theme.partials.*', 'errors.*', '*').
     *
     * @param string|list<string> $patterns
     * @param callable(ViewContext): (array<string, mixed>|null|void) $composer
     */
    public function composer(string|array $patterns, callable $composer): void
    {
        $list = is_array($patterns) ? array_values($patterns) : [$patterns];

        $this->composers[] = [
            'patterns' => $list,
            'composer' => Closure::fromCallable($composer),
        ];
    }

    /**
     * Resolve the final data array for a render: shared data, then the output of
     * every matching composer (memoized once per request), then the caller's
     * explicit data on top. Explicit data always wins on a key collision.
     *
     * @param array<string, mixed> $explicit
     *
     * @return array<string, mixed>
     */
    public function resolve(string $template, array $explicit): array
    {
        $stateKey = $this->currentKey();
        $state = $this->requestState[$stateKey] ?? null;
        if ($state === null) {
            $state = ['shared' => [], 'memo' => []];
        }

        // Hot-path skip: nothing shared and no composers registered.
        if ($this->composers === [] && $state['shared'] === []) {
            return $explicit;
        }

        $data = $state['shared'];

        foreach ($this->composers as $index => $registration) {
            if (!self::matches($registration['patterns'], $template)) {
                continue;
            }

            if (array_key_exists($index, $state['memo'])) {
                $output = $state['memo'][$index];
            } else {
                $context = new ViewContext($template, $data);
                $result = ($registration['composer'])($context);
                $output = [
                    ...$context->added(),
                    ...(is_array($result) ? self::stringKeyed($result) : []),
                ];
                $state['memo'][$index] = $output;
            }

            $data = [...$data, ...$output];
        }

        $this->requestState[$stateKey] = $state;

        /** @var array<string, mixed> $resolved Both operands carry string keys. */
        $resolved = [...$data, ...$explicit];

        return $resolved;
    }

    #[Override]
    public function resetRequestState(): void
    {
        unset($this->requestState[$this->currentKey()]);
    }

    /**
     * Keep only string-keyed entries of a composer's returned array. Template
     * data is string-keyed by contract (`extract()` skips numeric keys anyway);
     * dropping stray integer keys here keeps the merge well-typed instead of
     * silently corrupting it.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $output = [];

        foreach ($values as $name => $value) {
            if (is_string($name)) {
                $output[$name] = $value;
            }
        }

        return $output;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matches(array $patterns, string $template): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $template || fnmatch($pattern, $template)) {
                return true;
            }
        }

        return false;
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
