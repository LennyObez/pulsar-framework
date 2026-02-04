<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\CacheWarmupCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Core\Kernel;

#[CoversClass(CacheWarmupCommand::class)]
final class CacheWarmupCommandTest extends TestCase
{
    #[Test]
    public function nameIsCacheWarmup(): void
    {
        $command = new CacheWarmupCommand(new Kernel());

        self::assertSame('cache:warmup', $command->name);
    }

    #[Test]
    public function descriptionMentionsOptimize(): void
    {
        $command = new CacheWarmupCommand(new Kernel());

        self::assertStringContainsString('alias', $command->description);
    }

    #[Test]
    public function hasStrictAndEncryptOptions(): void
    {
        $command = new CacheWarmupCommand(new Kernel());

        self::assertArrayHasKey('strict', $command->options);
        self::assertArrayHasKey('encrypt', $command->options);
    }

    #[Test]
    public function delegatesToOptimizeCommand(): void
    {
        // Without FrameworkCache, OptimizeCommand returns error asking for PULSAR_MASTER_KEY
        $command = new CacheWarmupCommand(new Kernel());
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('cache:warmup'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('PULSAR_MASTER_KEY', $output->errorBuffer);
    }
}
