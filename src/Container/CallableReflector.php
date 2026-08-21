<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

use function explode;
use function is_array;
use function is_object;
use function is_string;
use function str_contains;

/**
 * Reflects a callable to obtain its parameter metadata.
 *
 * Supports closures, function names, [class, method] arrays,
 * invokable objects, and static method strings (Class::method).
 *
 * Published rather than internal because the extension sandbox now depends on
 * it: {@see \Pulsar\Extensibility\Internal\ScopedContainerProxy::call()} has to
 * resolve a callable's parameters through the extension's own scope instead of
 * the real container, and knowing which parameters a callable has is the whole
 * of that job. Keeping this private would have meant a second copy of PHP's
 * five callable shapes inside a security check, where the two copies drifting
 * apart is precisely the failure nobody would notice.
 *
 * The surface is one static method whose signature is fixed by PHP's own
 * reflection API, so publishing it commits the framework to nothing it was not
 * already committed to.
 */
#[Api(since: '1.0.0-rc.12')]
final class CallableReflector
{
    /**
     * Reflect a callable to get its ReflectionFunctionAbstract.
     *
     * @throws ReflectionException If the callable cannot be reflected
     */
    #[NoDiscard]
    public static function reflect(callable $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure) {
            return new ReflectionFunction($callable);
        }

        if (is_string($callable)) {
            if (str_contains($callable, '::')) {
                /** @var array{0: class-string, 1: string} $parts */
                $parts = explode('::', $callable, 2);

                return new ReflectionMethod($parts[0], $parts[1]);
            }

            return new ReflectionFunction($callable);
        }

        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        // Invokable object
        if (is_object($callable)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        throw new ReflectionException('Callable could not be reflected to a method.');
    }
}
