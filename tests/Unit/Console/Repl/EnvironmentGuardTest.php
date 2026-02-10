<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Console\Repl\EnvironmentGuard;

#[CoversClass(EnvironmentGuard::class)]
final class EnvironmentGuardTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('CI');
    }

    protected function tearDown(): void
    {
        putenv('CI');
    }

    #[Test]
    public function ciAlwaysBlocked(): void
    {
        putenv('CI=true');
        $environment = Environment::load();

        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, true);
        $result = $guard->canStart(true);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('CI', $result->reason);
    }

    #[Test]
    public function ciBlockedEvenWithForceFlag(): void
    {
        putenv('CI=1');
        $environment = Environment::load();

        $guard = new EnvironmentGuard(EnvironmentMode::Production, $environment, true);
        $result = $guard->canStart(true);

        self::assertFalse($result->allowed);
    }

    #[Test]
    public function ciNotBlockedWhenEmpty(): void
    {
        putenv('CI=');
        $environment = Environment::load();

        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, true);
        $result = $guard->canStart();

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function ciNotBlockedWhenZero(): void
    {
        putenv('CI=0');
        $environment = Environment::load();

        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, true);
        $result = $guard->canStart();

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function ciNotBlockedWhenFalse(): void
    {
        putenv('CI=false');
        $environment = Environment::load();

        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, true);
        $result = $guard->canStart();

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function productionRequiresConfigAndForceFlag(): void
    {
        $environment = Environment::load();
        $guard = new EnvironmentGuard(EnvironmentMode::Production, $environment, true);

        $result = $guard->canStart(true);

        self::assertTrue($result->allowed);
        self::assertTrue($result->isProductionOverride);
    }

    #[Test]
    public function productionBlockedWithoutForceFlag(): void
    {
        $environment = Environment::load();
        $guard = new EnvironmentGuard(EnvironmentMode::Production, $environment, true);

        $result = $guard->canStart(false);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('--i-know-what-im-doing', $result->reason);
    }

    #[Test]
    public function productionBlockedWithoutConfig(): void
    {
        $environment = Environment::load();
        $guard = new EnvironmentGuard(EnvironmentMode::Production, $environment, false);

        $result = $guard->canStart(true);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('not enabled', $result->reason);
    }

    #[Test]
    public function stagingRequiresConfig(): void
    {
        $environment = Environment::load();
        $guard = new EnvironmentGuard(EnvironmentMode::Staging, $environment, true);

        $result = $guard->canStart();

        self::assertTrue($result->allowed);
        self::assertFalse($result->isProductionOverride);
    }

    #[Test]
    public function stagingBlockedWithoutConfig(): void
    {
        $environment = Environment::load();
        $guard = new EnvironmentGuard(EnvironmentMode::Staging, $environment, false);

        $result = $guard->canStart();

        self::assertFalse($result->allowed);
    }

    #[Test]
    public function localRequiresConfig(): void
    {
        $environment = Environment::load();
        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, true);

        $result = $guard->canStart();

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function localBlockedWithoutConfig(): void
    {
        $environment = Environment::load();
        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, false);

        $result = $guard->canStart();

        self::assertFalse($result->allowed);
    }
}
