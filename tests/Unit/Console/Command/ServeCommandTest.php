<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ServeCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

use function in_array;

#[CoversClass(ServeCommand::class)]
final class ServeCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/pulsar-serve-test-' . bin2hex(random_bytes(4));
        mkdir($this->basePath);
    }

    protected function tearDown(): void
    {
        // Clean up
        if (is_dir($this->basePath . '/public')) {
            @rmdir($this->basePath . '/public');
        }

        @rmdir($this->basePath);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new ServeCommand($this->basePath);

        self::assertSame('serve', $command->name);
        self::assertSame('Start the PHP built-in development server', $command->description);
    }

    #[Test]
    public function hasExpectedOptions(): void
    {
        $command = new ServeCommand($this->basePath);

        self::assertArrayHasKey('host', $command->options);
        self::assertArrayHasKey('port', $command->options);
        self::assertArrayHasKey('docroot', $command->options);
        self::assertArrayHasKey('public', $command->options);
        self::assertArrayHasKey('check', $command->options);
    }

    #[Test]
    public function rejectsInvalidHost(): void
    {
        $command = new ServeCommand($this->basePath);
        $input = new ArrayInput('serve', [], ['host' => 'evil;rm -rf /']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Invalid host', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function rejectsInvalidPort(): void
    {
        $command = new ServeCommand($this->basePath);
        $input = new ArrayInput('serve', [], ['port' => '99999']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Invalid port', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function rejectsNonLoopbackWithoutPublicFlag(): void
    {
        $command = new ServeCommand($this->basePath);
        $input = new ArrayInput('serve', [], ['host' => '0.0.0.0']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('--public', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function acceptsLoopbackWithoutPublicFlag(): void
    {
        $command = new ServeCommand($this->basePath);
        $input = new ArrayInput('serve', [], ['host' => '127.0.0.1', 'port' => '8080', 'check' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        // --check validates configuration and exits before spawning the server.
        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('http://127.0.0.1:8080', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function acceptsLocalhostWithoutPublicFlag(): void
    {
        $command = new ServeCommand($this->basePath);
        $input = new ArrayInput('serve', [], ['host' => 'localhost', 'port' => '3000', 'check' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('http://localhost:3000', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function defaultsToPort8000AndLocalhost(): void
    {
        $command = new ServeCommand($this->basePath);
        $input = new ArrayInput('serve', [], ['check' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('http://127.0.0.1:8000', $output->buffer . $output->errorBuffer);
    }

    #[Test]
    public function buildCommandEscapesArguments(): void
    {
        $command = new ServeCommand($this->basePath);

        $result = $command->buildCommand('127.0.0.1', 8000, '/var/www', '/var/www/index.php');

        self::assertStringContainsString('-S', $result);
        self::assertStringContainsString('127.0.0.1:8000', $result);
        self::assertStringContainsString('/var/www', $result);
        self::assertStringContainsString('index.php', $result);
    }

    #[Test]
    public function buildCommandOmitsRouterWhenNull(): void
    {
        $command = new ServeCommand($this->basePath);

        $result = $command->buildCommand('127.0.0.1', 8000, '/var/www', null);

        self::assertStringNotContainsString('index.php', $result);
    }

    #[Test]
    #[DataProvider('validHostProvider')]
    public function acceptsValidHosts(string $host): void
    {
        $command = new ServeCommand($this->basePath);
        $options = ['host' => $host, 'port' => '8080', 'check' => true];

        // Loopback hosts don't need --public, others do
        $loopback = ['127.0.0.1', '::1', 'localhost'];

        if (!in_array($host, $loopback, true)) {
            $options['public'] = true;
        }

        $input = new ArrayInput('serve', [], $options);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validHostProvider(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'localhost' => ['localhost'];
        yield 'hostname' => ['myapp.local'];
    }

    #[Test]
    #[DataProvider('invalidHostProvider')]
    public function rejectsInvalidHosts(string $host): void
    {
        $command = new ServeCommand($this->basePath);
        $input = new ArrayInput('serve', [], ['host' => $host, 'public' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Invalid host', $output->buffer . $output->errorBuffer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHostProvider(): iterable
    {
        yield 'shell metachar semicolon' => ['host;whoami'];
        yield 'shell metachar backtick' => ['host`whoami`'];
        yield 'shell metachar pipe' => ['host|cat'];
        yield 'shell metachar dollar' => ['$(whoami)'];
        yield 'spaces' => ['host name'];
    }
}
