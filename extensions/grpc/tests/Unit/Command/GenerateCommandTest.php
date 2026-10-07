<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Grpc\Command\GenerateCommand;
use Pulsar\Extension\Grpc\Config\CodegenConfig;

#[CoversClass(GenerateCommand::class)]
final class GenerateCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $config = new CodegenConfig();
        $command = new GenerateCommand($config);

        self::assertSame('grpc:generate', $command->name);
        self::assertSame('Generate PHP code from proto files using protoc', $command->description);
    }

    #[Test]
    public function hasExpectedOptions(): void
    {
        $config = new CodegenConfig();
        $command = new GenerateCommand($config);

        self::assertArrayHasKey('output', $command->options);
        self::assertArrayHasKey('validate-only', $command->options);
    }

    #[Test]
    public function hasExpectedArguments(): void
    {
        $config = new CodegenConfig();
        $command = new GenerateCommand($config);

        self::assertCount(1, $command->arguments);
        self::assertSame('proto', $command->arguments[0]['name']);
        self::assertFalse($command->arguments[0]['required']);
    }

    #[Test]
    public function executeFailsWhenNoProtoFilesFound(): void
    {
        $tempDir = sys_get_temp_dir() . '/grpc_gen_test_' . uniqid();
        mkdir($tempDir, 0o755, true);

        try {
            $config = new CodegenConfig(protoPath: $tempDir);
            $command = new GenerateCommand($config);

            $input = $this->createStub(InputInterface::class);
            $input->method('hasOption')->willReturn(false);
            $input->method('getOption')->willReturn(null);
            $input->method('getArgument')->willReturn(null);

            $errors = [];
            $output = $this->createStub(OutputInterface::class);
            $output->method('errorln')->willReturnCallback(static function (string $msg = '') use (&$errors): void {
                $errors[] = $msg;
            });

            $exit = $command->execute($input, $output);

            self::assertSame(ExitCode::Error->value, $exit);
            $combined = implode("\n", $errors);
            self::assertStringContainsString('No proto files found', $combined);
        } finally {
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function usageShowsCommandName(): void
    {
        $config = new CodegenConfig();
        $command = new GenerateCommand($config);

        $usage = $command->getUsage();

        self::assertStringContainsString('grpc:generate', $usage);
    }
}
