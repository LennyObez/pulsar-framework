<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Exception;

use Pulsar\Api\Api;
use RuntimeException as BaseRuntimeException;

use function sprintf;

/**
 * Runtime-specific exceptions with static factory methods.
 * @api
 */
#[Api(since: '1.0.0')]
final class RuntimeException extends BaseRuntimeException
{
    public static function socketError(string $message): self
    {
        return new self(sprintf('Socket error: %s', $message));
    }

    public static function parseError(string $message): self
    {
        return new self(sprintf('HTTP parse error: %s', $message));
    }

    public static function payloadTooLarge(int $maxBytes): self
    {
        return new self(sprintf('Request body exceeds maximum size of %d bytes', $maxBytes));
    }

    public static function headersTooLarge(int $maxBytes): self
    {
        return new self(sprintf('Request headers exceed maximum size of %d bytes', $maxBytes));
    }

    public static function bindingRefused(string $host, int $port, string $reason): self
    {
        return new self(sprintf('Cannot bind to %s:%d: %s', $host, $port, $reason));
    }

    public static function extensionMissing(string $extension): self
    {
        return new self(sprintf(
            'Required extension "%s" is not loaded. Install or enable it in php.ini.',
            $extension,
        ));
    }

    public static function fatalError(string $message): self
    {
        return new self(sprintf('Fatal runtime error: %s', $message));
    }

    /**
     * More than one connection fiber was requested.
     *
     * The persistent runtime does not isolate per-request state across
     * interleaved fibers, so the setting is refused rather than honoured — see
     * {@see \Pulsar\Runtime\PersistentRuntime} for the full list of state that
     * crosses between requests.
     */
    public static function unsafeFiberConcurrency(int $requested): self
    {
        return new self(sprintf(
            'fiber_concurrency=%d is refused: the persistent runtime does not isolate per-request '
            . 'state across interleaved fibers. A fiber that suspends inside kernel->handle() — which '
            . 'Pulsar\Runtime\Fiber\CooperativeSleep does whenever a cache lock or stampede poll is '
            . 'contended — hands the worker to the next connection, whose beforeRequest()/afterRequest() '
            . 'then reset process-global state the suspended request is still using: the request-scoped '
            . 'container pool (Pulsar\Container\Scope\ScopeManager), Pulsar\Security\Session\SessionManager, '
            . 'Pulsar\FeatureFlag\FlagEvaluationLog, Pulsar\Cache\Application\CacheManager and the evicted '
            . 'Pulsar\Security\SecurityContext. One request can therefore observe another request\'s session '
            . 'and identity. Use 0 (synchronous accept loop) or 1 (one connection at a time), and scale with '
            . 'multiple worker processes behind a load balancer. Values above 1 never delivered I/O '
            . 'concurrency in any case: every socket read and write in the connection handler blocks the '
            . 'worker, so a connection fiber runs to completion before the next one is accepted.',
            $requested,
        ));
    }

    public static function unsupportedType(string $message): self
    {
        return new self($message);
    }
}
