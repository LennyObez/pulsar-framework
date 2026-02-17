<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\EnvDirective;

final class EnvDirectiveTest extends TestCase
{
    private EnvDirective $directive;

    protected function setUp(): void
    {
        $this->directive = new EnvDirective();
    }

    #[Test]
    public function nameReturnsEnv(): void
    {
        self::assertSame('env', $this->directive->name());
    }

    #[Test]
    public function compileGeneratesEnvironmentCheck(): void
    {
        $result = $this->directive->compile("'local'");

        self::assertStringContainsString('$__env', $result);
        self::assertStringContainsString("'local'", $result);
        self::assertStringContainsString('if', $result);
    }

    #[Test]
    public function compileWithProductionEnvironment(): void
    {
        $result = $this->directive->compile("'production'");

        self::assertStringContainsString("'production'", $result);
    }

    #[Test]
    public function compileWithEmptyExpressionReturnsFalse(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('false', $result);
    }

    #[Test]
    public function compileOutputIsValidPhp(): void
    {
        $compiled = $this->directive->compile("'staging'");

        // Should start with PHP open tag
        self::assertStringStartsWith('<?php', $compiled);
    }
}
