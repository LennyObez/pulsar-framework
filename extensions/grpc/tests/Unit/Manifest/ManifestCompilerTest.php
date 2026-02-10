<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Grpc\Unit\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;
use Pulsar\Extension\Grpc\Manifest\ManifestCompiler;
use Pulsar\Extension\Grpc\Server\ServiceRegistry;

use function dirname;

#[CoversClass(ManifestCompiler::class)]
final class ManifestCompilerTest extends TestCase
{
    private ManifestCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ManifestCompiler();
    }

    #[Test]
    public function compilesEmptyRegistry(): void
    {
        $registry = new ServiceRegistry();

        $manifest = $this->compiler->compile($registry);

        self::assertSame([], $manifest->services);
        self::assertSame('1.0', $manifest->version);
        self::assertNotSame('', $manifest->compiledAt);
    }

    #[Test]
    public function compilesRegistryWithOneService(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();
        $registry->register($handler);

        $manifest = $this->compiler->compile($registry);

        self::assertCount(1, $manifest->services);
        self::assertSame('helloworld.Greeter', $manifest->services[0]->serviceName);
        self::assertNotEmpty($manifest->services[0]->methods);
    }

    #[Test]
    public function compilesRegistryWithMultipleServices(): void
    {
        $registry = new ServiceRegistry();
        $registry->register($this->createGreeterHandler());
        $registry->register($this->createEchoHandler());

        $manifest = $this->compiler->compile($registry);

        self::assertCount(2, $manifest->services);

        $serviceNames = array_map(
            static fn($entry) => $entry->serviceName,
            $manifest->services,
        );

        self::assertContains('helloworld.Greeter', $serviceNames);
        self::assertContains('echo.Echo', $serviceNames);
    }

    #[Test]
    public function compiledManifestIncludesMethodData(): void
    {
        $registry = new ServiceRegistry();
        $registry->register($this->createGreeterHandler());

        $manifest = $this->compiler->compile($registry);

        $methods = $manifest->services[0]->methods;
        self::assertCount(1, $methods);
        self::assertSame('SayHello', $methods[0]['name']);
        self::assertSame('/helloworld.Greeter/SayHello', $methods[0]['full_name']);
        self::assertSame('unary', $methods[0]['type']);
    }

    #[Test]
    public function writePhpFileCreatesFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/manifest_compiler_' . uniqid();
        $outputPath = $tempDir . '/grpc-manifest.php';

        try {
            $registry = new ServiceRegistry();
            $registry->register($this->createGreeterHandler());

            $manifest = $this->compiler->compile($registry);
            $this->compiler->writePhpFile($manifest, $outputPath);

            self::assertFileExists($outputPath);

            $content = file_get_contents($outputPath);
            self::assertIsString($content);
            self::assertStringStartsWith('<?php', $content);
            self::assertStringContainsString('declare(strict_types=1)', $content);
            self::assertStringContainsString('return', $content);
        } finally {
            @unlink($outputPath);
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function writePhpFileCreatesParentDirectories(): void
    {
        $tempDir = sys_get_temp_dir() . '/manifest_deep_' . uniqid() . '/nested/dir';
        $outputPath = $tempDir . '/manifest.php';

        try {
            $registry = new ServiceRegistry();
            $manifest = $this->compiler->compile($registry);

            $this->compiler->writePhpFile($manifest, $outputPath);

            self::assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
            // Clean up nested dirs
            @rmdir($tempDir);
            @rmdir(dirname($tempDir));
            @rmdir(dirname($tempDir, 2));
        }
    }

    #[Test]
    public function compiledManifestRecordsHandlerClass(): void
    {
        $registry = new ServiceRegistry();
        $handler = $this->createGreeterHandler();
        $registry->register($handler);

        $manifest = $this->compiler->compile($registry);

        self::assertNotSame('', $manifest->services[0]->handlerClass);
    }

    private function createGreeterHandler(): ServiceHandlerInterface
    {
        $descriptor = new MethodDescriptor(
            name: 'SayHello',
            fullName: '/helloworld.Greeter/SayHello',
            type: MethodType::Unary,
            inputType: 'helloworld.HelloRequest',
            outputType: 'helloworld.HelloReply',
            handler: 'GreeterHandler::SayHello',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('serviceName')->willReturn('helloworld.Greeter');
        $handler->method('methods')->willReturn(['SayHello' => $descriptor]);
        $handler->method('invoke')->willReturn('');

        return $handler;
    }

    private function createEchoHandler(): ServiceHandlerInterface
    {
        $descriptor = new MethodDescriptor(
            name: 'Echo',
            fullName: '/echo.Echo/Echo',
            type: MethodType::Unary,
            inputType: 'echo.EchoRequest',
            outputType: 'echo.EchoResponse',
            handler: 'EchoHandler::Echo',
        );

        $handler = $this->createStub(ServiceHandlerInterface::class);
        $handler->method('serviceName')->willReturn('echo.Echo');
        $handler->method('methods')->willReturn(['Echo' => $descriptor]);
        $handler->method('invoke')->willReturn('');

        return $handler;
    }
}
