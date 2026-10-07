<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\ReplHelpers;
use Pulsar\Console\Repl\ResultPrinter;
use Pulsar\Console\Repl\SyntaxHighlighter;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use RuntimeException;
use stdClass;

#[CoversClass(ReplHelpers::class)]
final class ReplHelpersTest extends TestCase
{
    private ContainerInterface $container;
    private ReplHelpers $helpers;

    protected function setUp(): void
    {
        $this->container = $this->createStub(ContainerInterface::class);
        $printer = new ResultPrinter();
        $this->helpers = new ReplHelpers($this->container, $printer);
    }

    // --- dump() ---

    #[Test]
    public function dumpFormatsNull(): void
    {
        $result = $this->helpers->dump(null);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertSame('null', $stripped);
    }

    #[Test]
    public function dumpFormatsInteger(): void
    {
        $result = $this->helpers->dump(42);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertSame('42', $stripped);
    }

    #[Test]
    public function dumpFormatsString(): void
    {
        $result = $this->helpers->dump('hello');

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('hello', $stripped);
        self::assertStringContainsString('string(5)', $stripped);
    }

    #[Test]
    public function dumpFormatsArray(): void
    {
        $result = $this->helpers->dump([1, 2, 3]);

        $stripped = SyntaxHighlighter::stripAnsi($result);
        self::assertStringContainsString('array(3)', $stripped);
    }

    // --- route() ---

    #[Test]
    public function routeWithoutRouterShowsMessage(): void
    {
        $this->container = $this->createStub(ContainerInterface::class);
        $this->container->method('has')->willReturn(false);
        $this->helpers = new ReplHelpers($this->container, new ResultPrinter());

        $result = $this->helpers->route('some.route');

        self::assertStringContainsString('not available', $result);
    }

    #[Test]
    public function routeFindsNamedRoute(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: 'UserController@show',
            name: 'users.show',
            middleware: ['auth'],
            constraints: ['id' => '\d+'],
        );

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([$route]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($router);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->route('users.show');

        self::assertStringContainsString('users.show', $result);
        self::assertStringContainsString('GET', $result);
        self::assertStringContainsString('/users/{id}', $result);
        self::assertStringContainsString('UserController@show', $result);
        self::assertStringContainsString('auth', $result);
        self::assertStringContainsString('id=\d+', $result);
    }

    #[Test]
    public function routeNotFoundShowsMessage(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($router);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->route('nonexistent');

        self::assertStringContainsString('not found', $result);
    }

    #[Test]
    public function routeShowsHostWhenPresent(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/v1',
            handler: 'ApiController',
            name: 'api.v1',
            host: 'api.example.com',
        );

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([$route]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($router);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->route('api.v1');

        self::assertStringContainsString('api.example.com', $result);
    }

    // --- sql() ---

    #[Test]
    public function sqlWithoutConnectionShowsMessage(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->sql('SELECT 1');

        self::assertStringContainsString('not available', $result);
    }

    #[Test]
    public function sqlRendersResultAsTable(): void
    {
        $rows = [
            new Row(['id' => 1, 'name' => 'Alice']),
            new Row(['id' => 2, 'name' => 'Bob']),
        ];

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result($rows));

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($connection);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->sql('SELECT id, name FROM users');

        self::assertStringContainsString('Alice', $result);
        self::assertStringContainsString('Bob', $result);
        self::assertStringContainsString('2 row(s)', $result);
    }

    #[Test]
    public function sqlEmptyResultShowsMessage(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($connection);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->sql('SELECT * FROM empty_table');

        self::assertStringContainsString('empty result set', $result);
    }

    #[Test]
    public function sqlShowsErrorOnException(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willThrowException(new RuntimeException('syntax error near X'));

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($connection);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->sql('INVALID SQL');

        self::assertStringContainsString('SQL Error', $result);
        self::assertStringContainsString('syntax error near X', $result);
    }

    #[Test]
    public function sqlHandlesNullValues(): void
    {
        $rows = [new Row(['id' => 1, 'email' => null])];

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result($rows));

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($connection);

        $helpers = new ReplHelpers($container, new ResultPrinter());
        $result = $helpers->sql('SELECT id, email FROM users');

        self::assertStringContainsString('NULL', $result);
    }

    // --- doc() ---

    #[Test]
    public function docShowsClassInfo(): void
    {
        $result = $this->helpers->doc(stdClass::class);

        self::assertStringContainsString('Class: stdClass', $result);
    }

    #[Test]
    public function docShowsMethodInfo(): void
    {
        $result = $this->helpers->doc(ReplHelpers::class, 'dump');

        self::assertStringContainsString('Method: ', $result);
        self::assertStringContainsString('dump', $result);
        self::assertStringContainsString('Signature:', $result);
    }

    #[Test]
    public function docNonexistentClassShowsMessage(): void
    {
        $nonexistent = 'Nonexistent\\Class\\Here';
        $result = $this->helpers->doc($nonexistent); // @phpstan-ignore argument.type

        self::assertStringContainsString('not found', $result);
    }

    #[Test]
    public function docNonexistentMethodShowsMessage(): void
    {
        $result = $this->helpers->doc(stdClass::class, 'noSuchMethod');

        self::assertStringContainsString('not found', $result);
    }

    #[Test]
    public function docShowsMethodVisibility(): void
    {
        $result = $this->helpers->doc(ReplHelpers::class, 'dump');

        self::assertStringContainsString('Visibility: public', $result);
    }

    // --- bench() ---

    #[Test]
    public function benchRunsCallbackAndReportsStats(): void
    {
        $counter = 0;
        $result = $this->helpers->bench(static function () use (&$counter): void {
            $counter++;
        }, iterations: 10);

        // The callback should have been called 10 times + 1 warmup
        self::assertSame(11, $counter);

        self::assertStringContainsString('Benchmark: 10 iterations', $result);
        self::assertStringContainsString('Total:', $result);
        self::assertStringContainsString('Average:', $result);
        self::assertStringContainsString('Min:', $result);
        self::assertStringContainsString('Max:', $result);
        self::assertStringContainsString('Memory:', $result);
    }

    #[Test]
    public function benchWithZeroIterationsReturnsError(): void
    {
        $result = $this->helpers->bench(static fn(): int => 1, iterations: 0);

        self::assertStringContainsString('Iterations must be >= 1', $result);
    }

    #[Test]
    public function benchWithSingleIteration(): void
    {
        $result = $this->helpers->bench(static fn(): int => 1, iterations: 1);

        self::assertStringContainsString('1 iterations', $result);
        self::assertStringContainsString('ms', $result);
    }

    // --- profile() ---

    #[Test]
    public function profileReportsTimeAndMemory(): void
    {
        $result = $this->helpers->profile(static fn(): string => str_repeat('x', 1000));

        self::assertStringContainsString('Result:', $result);
        self::assertStringContainsString('Time:', $result);
        self::assertStringContainsString('Memory:', $result);
        self::assertStringContainsString('ms', $result);
    }

    #[Test]
    public function profileReportsExceptionOnFailure(): void
    {
        $result = $this->helpers->profile(static function (): never {
            throw new RuntimeException('Profiled failure');
        });

        self::assertStringContainsString('Exception:', $result);
        self::assertStringContainsString('Profiled failure', $result);
        self::assertStringContainsString('Time:', $result);
    }

    // --- helperNames() ---

    #[Test]
    public function helperNamesReturnsAllHelpers(): void
    {
        $names = ReplHelpers::helperNames();

        self::assertContains('dump', $names);
        self::assertContains('route', $names);
        self::assertContains('sql', $names);
        self::assertContains('doc', $names);
        self::assertContains('bench', $names);
        self::assertContains('profile', $names);
    }
}
