<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Deploy\Check\EnvironmentValidationCheck;
use Pulsar\Deploy\CheckSeverity;

use function putenv;

#[CoversClass(EnvironmentValidationCheck::class)]
final class EnvironmentValidationCheckTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ENV');
        putenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('APP_DEBUG');
    }

    #[Test]
    public function passesWhenValuesAreRecognized(): void
    {
        putenv('APP_ENV=production');
        putenv('APP_DEBUG=false');

        $result = new EnvironmentValidationCheck(Environment::load())->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function errorsOnAnUnrecognizedAppEnv(): void
    {
        // A typo that the fail-secure default would silently map to production.
        putenv('APP_ENV=prod');

        $result = new EnvironmentValidationCheck(Environment::load())->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString("APP_ENV='prod'", $result->message);
        self::assertNotSame([], $result->recommendations);
    }

    #[Test]
    public function errorsOnAnUnrecognizedAppDebug(): void
    {
        putenv('APP_ENV=production');
        putenv('APP_DEBUG=treu');

        $result = new EnvironmentValidationCheck(Environment::load())->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('APP_DEBUG', $result->message);
    }

    #[Test]
    public function acceptsBooleanAliasesForAppDebug(): void
    {
        putenv('APP_ENV=staging');
        putenv('APP_DEBUG=1');

        $result = new EnvironmentValidationCheck(Environment::load())->check('staging');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }
}
