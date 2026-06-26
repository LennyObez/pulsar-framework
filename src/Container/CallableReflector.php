<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Closure;
use NoDiscard;
use Pulsar\Api\Internal;
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
 */
#[Internal]
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
            /** @var array{0: object|class-string, 1: string} $callable */
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        // Invokable object
        if (is_object($callable)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        throw new ReflectionException('Callable could not be reflected to a method.');
    }
}
