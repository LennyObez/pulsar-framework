<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Runtime\PhpRuntime;
use Pulsar\Deploy\Runtime\PhpRuntimeInterface;

#[CoversClass(PhpRuntime::class)]
final class PhpRuntimeTest extends TestCase
{
    private PhpRuntime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new PhpRuntime();
    }

    #[Test]
    public function implementsInterface(): void
    {
        self::assertInstanceOf(PhpRuntimeInterface::class, $this->runtime);
    }

    #[Test]
    public function iniGetReturnsValueForKnownDirective(): void
    {
        $result = $this->runtime->iniGet('display_errors');

        // display_errors always exists; value may vary but should be string or false
        self::assertIsNotBool($result);
    }

    #[Test]
    public function iniGetReturnsFalseForNonExistentDirective(): void
    {
        $result = $this->runtime->iniGet('pulsar_nonexistent_directive_xyz');

        self::assertFalse($result);
    }

    #[Test]
    public function extensionLoadedReturnsTrueForCoreExtension(): void
    {
        // 'Core' extension is always loaded
        self::assertTrue($this->runtime->extensionLoaded('Core'));
    }

    #[Test]
    public function extensionLoadedReturnsFalseForNonExistentExtension(): void
    {
        self::assertFalse($this->runtime->extensionLoaded('pulsar_nonexistent_extension'));
    }

    #[Test]
    public function functionExistsReturnsTrueForBuiltinFunction(): void
    {
        self::assertTrue($this->runtime->functionExists('strlen'));
    }

    #[Test]
    public function functionExistsReturnsFalseForNonExistentFunction(): void
    {
        self::assertFalse($this->runtime->functionExists('pulsar_nonexistent_function'));
    }
}
