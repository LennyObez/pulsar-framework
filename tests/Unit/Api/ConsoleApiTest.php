<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Console\Application;
use Pulsar\Console\CommandInterface;
use Pulsar\Console\Exception\CommandNotFoundException;
use Pulsar\Console\Exception\ConsoleException;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Console\Verbosity;

#[CoversClass(Api::class)]
final class ConsoleApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function commandInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(CommandInterface::class);
    }

    #[Test]
    public function inputInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(InputInterface::class);
    }

    #[Test]
    public function outputInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(OutputInterface::class);
    }

    #[Test]
    public function exitCodeIsPublicApi(): void
    {
        self::assertHasApiAttribute(ExitCode::class);
        self::assertEnumCases(ExitCode::class, ['Success', 'Error', 'Invalid']);
    }

    #[Test]
    public function verbosityIsPublicApi(): void
    {
        self::assertHasApiAttribute(Verbosity::class);
    }

    #[Test]
    public function consoleExceptionsArePublicApi(): void
    {
        self::assertHasApiAttribute(ConsoleException::class);
        self::assertHasApiAttribute(CommandNotFoundException::class);
    }

    #[Test]
    public function applicationIsInternal(): void
    {
        self::assertHasInternalAttribute(Application::class);
    }
}
