<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Check\JitCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Tests\Unit\Deploy\Runtime\StubPhpRuntime;

#[CoversClass(JitCheck::class)]
final class JitCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new JitCheck($this->runtimeWithJit());

        self::assertSame('jit', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new JitCheck($this->runtimeWithJit());

        self::assertStringContainsString('JIT', $check->getDescription());
    }

    #[Test]
    public function it_passes_in_local_environment(): void
    {
        $check = new JitCheck(new StubPhpRuntime());

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_warns_when_opcache_not_loaded_in_production(): void
    {
        $check = new JitCheck(new StubPhpRuntime());

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('not loaded', $result->message);
    }

    #[Test]
    public function it_warns_when_opcache_not_loaded_in_staging(): void
    {
        $check = new JitCheck(new StubPhpRuntime());

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    #[Test]
    public function it_warns_when_opcache_disabled_in_production(): void
    {
        $check = new JitCheck(new StubPhpRuntime(
            iniValues: ['opcache.enable' => '0', 'opcache.enable_cli' => '0'],
            loadedExtensions: ['Zend OPcache'],
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('not enabled', $result->message);
    }

    #[Test]
    public function it_warns_when_jit_disabled_in_production(): void
    {
        $check = new JitCheck($this->runtimeWithoutJit());

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('not enabled', $result->message);
    }

    #[Test]
    public function it_warns_when_jit_disabled_in_staging(): void
    {
        $check = new JitCheck($this->runtimeWithoutJit());

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
    }

    #[Test]
    public function it_warns_when_buffer_too_small_in_production(): void
    {
        $check = new JitCheck(new StubPhpRuntime(
            iniValues: [
                'opcache.enable' => '1',
                'opcache.enable_cli' => '1',
                'opcache.jit' => 'tracing',
                'opcache.jit_buffer_size' => '32M',
            ],
            loadedExtensions: ['Zend OPcache'],
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('buffer size', $result->message);
    }

    #[Test]
    public function it_passes_when_buffer_adequate_in_staging(): void
    {
        $check = new JitCheck(new StubPhpRuntime(
            iniValues: [
                'opcache.enable' => '1',
                'opcache.enable_cli' => '1',
                'opcache.jit' => 'tracing',
                'opcache.jit_buffer_size' => '32M',
            ],
            loadedExtensions: ['Zend OPcache'],
        ));

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Pass, $result->severity);
    }

    #[Test]
    public function it_passes_when_fully_configured(): void
    {
        $check = new JitCheck($this->runtimeWithJit());

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('128M', $result->message);
    }

    /**
     * @param string $jitValue The opcache.jit ini value
     */
    #[Test]
    #[DataProvider('disabledJitValues')]
    public function it_detects_disabled_jit(string $jitValue): void
    {
        $check = new JitCheck(new StubPhpRuntime(
            iniValues: [
                'opcache.enable' => '1',
                'opcache.enable_cli' => '1',
                'opcache.jit' => $jitValue,
            ],
            loadedExtensions: ['Zend OPcache'],
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity, "JIT value '$jitValue' should be detected as disabled");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disabledJitValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'off' => ['off'];
        yield 'disable' => ['disable'];
        yield 'disabled' => ['disabled'];
        yield 'empty' => [''];
        yield 'OFF uppercase' => ['OFF'];
    }

    /**
     * @param string $jitValue The opcache.jit ini value
     */
    #[Test]
    #[DataProvider('enabledJitValues')]
    public function it_detects_enabled_jit(string $jitValue): void
    {
        $check = new JitCheck(new StubPhpRuntime(
            iniValues: [
                'opcache.enable' => '1',
                'opcache.enable_cli' => '1',
                'opcache.jit' => $jitValue,
                'opcache.jit_buffer_size' => '128M',
            ],
            loadedExtensions: ['Zend OPcache'],
        ));

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity, "JIT value '$jitValue' should be detected as enabled");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function enabledJitValues(): iterable
    {
        yield 'tracing' => ['tracing'];
        yield 'function' => ['function'];
        yield 'on' => ['on'];
        yield '1' => ['1'];
        yield '1205' => ['1205'];
        yield '1235' => ['1235'];
        yield '1255' => ['1255'];
    }

    #[Test]
    public function production_recommendations_mention_sapi(): void
    {
        $check = new JitCheck($this->runtimeWithoutJit());

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('SAPI', $joined);
    }

    private function runtimeWithJit(): StubPhpRuntime
    {
        return new StubPhpRuntime(
            iniValues: [
                'opcache.enable' => '1',
                'opcache.enable_cli' => '1',
                'opcache.jit' => 'tracing',
                'opcache.jit_buffer_size' => '128M',
            ],
            loadedExtensions: ['Zend OPcache'],
        );
    }

    private function runtimeWithoutJit(): StubPhpRuntime
    {
        return new StubPhpRuntime(
            iniValues: [
                'opcache.enable' => '1',
                'opcache.enable_cli' => '1',
                'opcache.jit' => '0',
            ],
            loadedExtensions: ['Zend OPcache'],
        );
    }
}
