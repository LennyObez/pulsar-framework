<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\Command\AuditPipelineCommand;

#[CoversClass(AuditPipelineCommand::class)]
final class AuditPipelineCommandTest extends TestCase
{
    #[Test]
    public function configurationSetsNameAndDescription(): void
    {
        $command = new AuditPipelineCommand('/tmp/project');

        self::assertSame('supply-chain:audit-pipeline', $command->name);
        self::assertSame('Audit CI/CD pipeline configurations for security compliance', $command->description);
    }

    #[Test]
    public function executeReturnsSuccessForNonExistentWorkflowDirectory(): void
    {
        // When no .github/workflows/ exists, the auditor finds no workflows,
        // meaning all required tools are missing and the pipeline is non-compliant
        $command = new AuditPipelineCommand('/tmp/non-existent-project-' . bin2hex(random_bytes(4)));
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        // Non-compliant because required tools are missing from empty workflow set
        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsSuccessWhenProjectHasCompliantPipeline(): void
    {
        // Create a temporary project with a compliant workflow
        $tempDir = sys_get_temp_dir() . '/pulsar-audit-test-' . bin2hex(random_bytes(4));
        $workflowDir = $tempDir . '/.github/workflows';
        mkdir($workflowDir, 0o777, true);

        $workflowContent = <<<'YAML'
            name: CI
            on: [push]
            jobs:
              quality:
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@a5ac7e51b41094c92402da3b24376905380afc29
                  - name: PHPStan
                    run: vendor/bin/phpstan analyse
                  - name: Psalm
                    run: vendor/bin/psalm
                  - name: Composer Audit
                    run: composer audit
                  - name: Deptrac
                    run: vendor/bin/deptrac analyse
            YAML;

        file_put_contents($workflowDir . '/ci.yml', $workflowContent);

        $command = new AuditPipelineCommand($tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        // Clean up
        unlink($workflowDir . '/ci.yml');
        rmdir($workflowDir);
        rmdir($tempDir . '/.github');
        rmdir($tempDir);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenToolsAreMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-audit-missing-' . bin2hex(random_bytes(4));
        $workflowDir = $tempDir . '/.github/workflows';
        mkdir($workflowDir, 0o777, true);

        // Workflow with only phpstan - missing psalm, composer-audit, deptrac
        $workflowContent = <<<'YAML'
            name: CI
            on: [push]
            jobs:
              lint:
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@a5ac7e51b41094c92402da3b24376905380afc29
                  - name: PHPStan
                    run: vendor/bin/phpstan analyse
            YAML;

        file_put_contents($workflowDir . '/ci.yml', $workflowContent);

        $command = new AuditPipelineCommand($tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($workflowDir . '/ci.yml');
        rmdir($workflowDir);
        rmdir($tempDir . '/.github');
        rmdir($tempDir);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenBypassesDetected(): void
    {
        $tempDir = sys_get_temp_dir() . '/pulsar-audit-bypass-' . bin2hex(random_bytes(4));
        $workflowDir = $tempDir . '/.github/workflows';
        mkdir($workflowDir, 0o777, true);

        // All tools present but one has continue-on-error: true
        $workflowContent = <<<'YAML'
            name: CI
            on: [push]
            jobs:
              quality:
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@a5ac7e51b41094c92402da3b24376905380afc29
                  - name: PHPStan
                    run: vendor/bin/phpstan analyse
                    continue-on-error: true
                  - name: Psalm
                    run: vendor/bin/psalm
                  - name: Audit
                    run: composer audit
                  - name: Deptrac
                    run: vendor/bin/deptrac analyse
            YAML;

        file_put_contents($workflowDir . '/ci.yml', $workflowContent);

        $command = new AuditPipelineCommand($tempDir);
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $exitCode = $command->execute($input, $output);

        unlink($workflowDir . '/ci.yml');
        rmdir($workflowDir);
        rmdir($tempDir . '/.github');
        rmdir($tempDir);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }
}
