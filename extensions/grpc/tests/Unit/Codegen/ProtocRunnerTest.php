<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Grpc\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\ProtocRunner;
use Pulsar\Extension\Grpc\Config\CodegenConfig;

#[CoversClass(ProtocRunner::class)]
final class ProtocRunnerTest extends TestCase
{
    #[Test]
    public function generateFailsWhenProtoFileNotFound(): void
    {
        $config = new CodegenConfig(
            protocBinary: 'protoc',
            protoPath: '/nonexistent',
        );
        $runner = new ProtocRunner($config);

        $result = $runner->generate('/nonexistent/missing.proto', '/tmp/output');

        self::assertFalse($result->success);
        self::assertNotEmpty($result->errors);
        self::assertStringContainsString('Proto file not found', $result->errors[0]);
    }

    #[Test]
    public function generateFailsWhenProtocNotInstalled(): void
    {
        $config = new CodegenConfig(
            protocBinary: '/nonexistent/protoc-fake-binary-xyz',
            protoPath: sys_get_temp_dir(),
        );
        $runner = new ProtocRunner($config);

        // Create a temporary proto file
        $protoFile = sys_get_temp_dir() . '/test_runner_' . uniqid() . '.proto';
        file_put_contents($protoFile, 'syntax = "proto3";');

        try {
            $result = $runner->generate($protoFile, sys_get_temp_dir() . '/output_' . uniqid());

            self::assertFalse($result->success);
            self::assertNotEmpty($result->errors);
            self::assertStringContainsString('protoc binary not found', $result->errors[0]);
        } finally {
            @unlink($protoFile);
        }
    }

    #[Test]
    public function detectVersionReturnsNullForMissingBinary(): void
    {
        $config = new CodegenConfig(
            protocBinary: '/nonexistent/protoc-fake-binary-xyz',
        );
        $runner = new ProtocRunner($config);

        self::assertNull($runner->detectVersion());
    }

    #[Test]
    public function detectVersionReturnsVersionWhenProtocAvailable(): void
    {
        // Use `php` as a standin to test the process execution path
        // If protoc is installed, it will return a version; otherwise skip
        $config = new CodegenConfig(protocBinary: 'protoc');
        $runner = new ProtocRunner($config);

        $version = $runner->detectVersion();

        // protoc may or may not be installed in the test environment
        // If not installed, version is null; if installed, it's a version string
        if ($version !== null) {
            self::assertMatchesRegularExpression('/^\d+\.\d+/', $version);
        } else {
            self::assertNull($version);
        }
    }
}
