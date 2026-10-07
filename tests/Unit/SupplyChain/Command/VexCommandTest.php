<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\Command\VexCommand;

#[CoversClass(VexCommand::class)]
final class VexCommandTest extends TestCase
{
    #[Test]
    public function configurationSetsNameAndDescription(): void
    {
        $command = new VexCommand('/tmp/project');

        self::assertSame('supply-chain:vex', $command->name);
        self::assertSame('Generate a VEX document for known vulnerabilities', $command->description);
    }

    #[Test]
    public function configurationRegistersOutputOption(): void
    {
        $command = new VexCommand('/tmp/project');

        self::assertArrayHasKey('output', $command->options);
        self::assertSame('o', $command->options['output']['shortcut']);
    }

    #[Test]
    public function executeWritesVexDocumentToDefaultPath(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-vex-test-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $command = new VexCommand($tempDir);

        $vexPath = $tempDir . '/vex.json';

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);
        $input->method('getStringOption')->willReturn($vexPath);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        $fileExists = file_exists($vexPath);

        // Clean up
        if ($fileExists) {
            unlink($vexPath);
        }
        rmdir($tempDir);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertTrue($fileExists, 'VEX file should be created at default path');
    }

    #[Test]
    public function executeWritesVexDocumentToCustomPath(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-vex-custom-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $customPath = $tempDir . '/custom-vex-output.json';

        $command = new VexCommand($tempDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnMap([
            ['output', true],
        ]);
        $input->method('getStringOption')->willReturn($customPath);

        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        $fileExists = file_exists($customPath);

        // Clean up
        if ($fileExists) {
            unlink($customPath);
        }
        rmdir($tempDir);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertTrue($fileExists, 'VEX file should be created at custom path');
    }

    #[Test]
    public function executeProducesValidJsonOutput(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-vex-json-' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0o777, true);

        $command = new VexCommand($tempDir);

        $vexPath = $tempDir . '/vex.json';

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);
        $input->method('getStringOption')->willReturn($vexPath);

        $output = $this->createStub(OutputInterface::class);

        $command->execute($input, $output);

        $json = file_get_contents($vexPath);

        // Clean up
        unlink($vexPath);
        rmdir($tempDir);

        self::assertIsString($json);
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('@context', $decoded);
        self::assertArrayHasKey('statements', $decoded);
    }
}
