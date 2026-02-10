<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Grpc\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Grpc\Command\ServeCommand;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Config\TlsConfig;
use Pulsar\Extension\Grpc\Server\GrpcServerInterface;
use RuntimeException;

#[CoversClass(ServeCommand::class)]
final class ServeCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $server = $this->createStub(GrpcServerInterface::class);
        $config = new GrpcConfig();

        $command = new ServeCommand($server, $config);

        self::assertSame('grpc:serve', $command->name);
        self::assertSame('Start the gRPC server', $command->description);
    }

    #[Test]
    public function hasExpectedOptions(): void
    {
        $server = $this->createStub(GrpcServerInterface::class);
        $config = new GrpcConfig();

        $command = new ServeCommand($server, $config);

        self::assertArrayHasKey('host', $command->options);
        self::assertArrayHasKey('port', $command->options);
        self::assertArrayHasKey('workers', $command->options);
        self::assertArrayHasKey('tls-cert', $command->options);
        self::assertArrayHasKey('tls-key', $command->options);
    }

    #[Test]
    public function executeStartsServer(): void
    {
        $server = $this->createMock(GrpcServerInterface::class);
        $server->expects(self::once())->method('start');

        $config = new GrpcConfig();
        $command = new ServeCommand($server, $config);

        $input = $this->createInputStub();
        $output = $this->createOutputStub();

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);
    }

    #[Test]
    public function executeReportsAdapterName(): void
    {
        $server = $this->createStub(GrpcServerInterface::class);
        $config = new GrpcConfig(adapter: 'roadrunner');

        $command = new ServeCommand($server, $config);

        $messages = [];
        $output = $this->createOutputCapture($messages);
        $input = $this->createInputStub();

        $command->execute($input, $output);

        $combined = implode("\n", $messages);
        self::assertStringContainsString('roadrunner', $combined);
    }

    #[Test]
    public function executeReportsTlsEnabled(): void
    {
        $server = $this->createStub(GrpcServerInterface::class);
        $config = new GrpcConfig(
            tls: new TlsConfig(enabled: true, certPath: '/cert.pem', keyPath: '/key.pem'),
        );

        $command = new ServeCommand($server, $config);

        $messages = [];
        $output = $this->createOutputCapture($messages);
        $input = $this->createInputStub();

        $command->execute($input, $output);

        $combined = implode("\n", $messages);
        self::assertStringContainsString('TLS enabled', $combined);
    }

    #[Test]
    public function executeReportsMtlsEnabled(): void
    {
        $server = $this->createStub(GrpcServerInterface::class);
        $config = new GrpcConfig(
            tls: new TlsConfig(
                enabled: true,
                certPath: '/cert.pem',
                keyPath: '/key.pem',
                caPath: '/ca.pem',
                mutual: true,
            ),
        );

        $command = new ServeCommand($server, $config);

        $messages = [];
        $output = $this->createOutputCapture($messages);
        $input = $this->createInputStub();

        $command->execute($input, $output);

        $combined = implode("\n", $messages);
        self::assertStringContainsString('Mutual TLS', $combined);
    }

    #[Test]
    public function executeReturnsErrorOnServerFailure(): void
    {
        $server = $this->createStub(GrpcServerInterface::class);
        $server->method('start')->willThrowException(
            new RuntimeException('No services registered'),
        );

        $config = new GrpcConfig();
        $command = new ServeCommand($server, $config);

        $input = $this->createInputStub();
        $errors = [];
        $output = $this->createOutputCapture(errors: $errors);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exit);
        $combined = implode("\n", $errors);
        self::assertStringContainsString('Failed to start gRPC server', $combined);
    }

    #[Test]
    public function usageIncludesAllOptions(): void
    {
        $server = $this->createStub(GrpcServerInterface::class);
        $config = new GrpcConfig();

        $command = new ServeCommand($server, $config);
        $usage = $command->getUsage();

        self::assertStringContainsString('grpc:serve', $usage);
        self::assertStringContainsString('--host', $usage);
        self::assertStringContainsString('--port', $usage);
    }

    private function createInputStub(): InputInterface
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);
        $input->method('getOption')->willReturn(null);

        return $input;
    }

    /**
     * @param list<string> $messages
     * @param list<string> $errors
     */
    private function createOutputCapture(array &$messages = [], array &$errors = []): OutputInterface
    {
        $output = $this->createStub(OutputInterface::class);
        $output->method('info')->willReturnCallback(static function (string $msg) use (&$messages): void {
            $messages[] = $msg;
        });
        $output->method('writeln')->willReturnCallback(static function (string $msg = '') use (&$messages): void {
            $messages[] = $msg;
        });
        $output->method('write')->willReturnCallback(static function (string $msg) use (&$messages): void {
            $messages[] = $msg;
        });
        $output->method('errorln')->willReturnCallback(static function (string $msg = '') use (&$errors): void {
            $errors[] = $msg;
        });
        $output->method('error')->willReturnCallback(static function (string $msg) use (&$errors): void {
            $errors[] = $msg;
        });

        return $output;
    }

    private function createOutputStub(): OutputInterface
    {
        return $this->createStub(OutputInterface::class);
    }
}
