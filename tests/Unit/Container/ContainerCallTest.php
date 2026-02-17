<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\CallableReflector;
use Pulsar\Container\Container;
use Pulsar\Container\Exception\ContainerException;
use stdClass;

use function is_int;
use function is_string;

#[CoversClass(Container::class)]
#[CoversClass(CallableReflector::class)]
final class ContainerCallTest extends TestCase
{
    #[Test]
    public function callResolvesTypeHintedParametersFromContainer(): void
    {
        $container = new Container();
        $dep = new stdClass();
        $dep->value = 'from-container';
        $container->instance(stdClass::class, $dep);

        $result = $container->call(function (stdClass $obj): string {
            /** @var mixed $value */
            $value = $obj->value;

            return is_string($value) ? $value : '';
        });

        self::assertSame('from-container', $result);
    }

    #[Test]
    public function callUsesExplicitParametersOverContainer(): void
    {
        $container = new Container();
        $containerObj = new stdClass();
        $containerObj->value = 'container';
        $container->instance(stdClass::class, $containerObj);

        $explicit = new stdClass();
        $explicit->value = 'explicit';

        $result = $container->call(
            function (stdClass $obj): string {
                /** @var mixed $value */
                $value = $obj->value;

                return is_string($value) ? $value : '';
            },
            ['obj' => $explicit],
        );

        self::assertSame('explicit', $result);
    }

    #[Test]
    public function callPassesExplicitScalarParameters(): void
    {
        $container = new Container();

        $result = $container->call(
            function (string $name, int $age): string {
                return "$name:$age";
            },
            ['name' => 'Alice', 'age' => 30],
        );

        self::assertSame('Alice:30', $result);
    }

    #[Test]
    public function callUsesDefaultValuesForMissingParameters(): void
    {
        $container = new Container();

        $result = $container->call(
            function (string $required, string $optional = 'default'): string {
                return "$required-$optional";
            },
            ['required' => 'provided'],
        );

        self::assertSame('provided-default', $result);
    }

    #[Test]
    public function callResolvesNullableToNullWhenNotInContainer(): void
    {
        $container = new Container();

        $result = $container->call(function (?stdClass $obj): string {
            /** @var mixed $value */
            $value = $obj?->value;

            return is_string($value) ? $value : 'null-received';
        });

        self::assertSame('null-received', $result);
    }

    #[Test]
    public function callThrowsForUnresolvableRequiredParameter(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve parameter/');

        $container->call(function (string $required): void {});
    }

    #[Test]
    public function callWorksWithClosures(): void
    {
        $container = new Container();

        $result = $container->call(function (): int {
            return 42;
        });

        self::assertSame(42, $result);
    }

    #[Test]
    public function callWorksWithInvokableObject(): void
    {
        $container = new Container();

        $invokable = new class {
            public function __invoke(string $msg = 'hello'): string
            {
                return $msg;
            }
        };

        $result = $container->call($invokable);

        self::assertSame('hello', $result);
    }

    #[Test]
    public function callMixesContainerAndExplicitParameters(): void
    {
        $container = new Container();
        $obj = new stdClass();
        $obj->id = 99;
        $container->instance(stdClass::class, $obj);

        $result = $container->call(
            function (stdClass $obj, string $label): string {
                /** @var mixed $id */
                $id = $obj->id;

                return $label . ':' . (is_int($id) ? $id : 0);
            },
            ['label' => 'item'],
        );

        self::assertSame('item:99', $result);
    }
}
