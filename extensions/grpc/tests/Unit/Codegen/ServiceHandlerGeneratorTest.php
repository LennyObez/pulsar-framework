<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Grpc\Unit\Codegen;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\ServiceHandlerGenerator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ServiceHandlerGenerator::class)]
final class ServiceHandlerGeneratorTest extends TestCase
{
    private ServiceHandlerGenerator $generator;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->generator = new ServiceHandlerGenerator();
        $this->tempDir = sys_get_temp_dir() . '/grpc_handler_gen_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function generateReturnsEmptyWhenNoServicesFound(): void
    {
        // Create a regular PHP file (not a gRPC client stub)
        $file = $this->tempDir . '/RegularClass.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace App\Regular;
            class RegularClass {}
            PHP);

        $result = $this->generator->generate([$file], $this->tempDir . '/output', 'App\\Handler');

        self::assertSame([], $result);
    }

    #[Test]
    public function generateCreatesHandlerFromClientStub(): void
    {
        // Simulate a protoc-generated gRPC client stub
        $file = $this->tempDir . '/GreeterClient.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace Helloworld;

            class GreeterClient extends \Grpc\BaseStub
            {
                public function SayHello(\Helloworld\HelloRequest $argument, $metadata = [])
                {
                }

                public function SayGoodbye(\Helloworld\GoodbyeRequest $argument, $metadata = [])
                {
                }
            }
            PHP);

        $outputDir = $this->tempDir . '/handlers';
        $result = $this->generator->generate([$file], $outputDir, 'App\\Grpc\\Handler');

        self::assertCount(1, $result);
        self::assertFileExists($result[0]);

        $content = file_get_contents($result[0]);
        self::assertIsString($content);
        self::assertStringContainsString('AbstractGreeterHandler', $content);
        self::assertStringContainsString('ServiceHandlerInterface', $content);
        self::assertStringContainsString('handleSayHello', $content);
        self::assertStringContainsString('handleSayGoodbye', $content);
        self::assertStringContainsString('namespace App\\Grpc\\Handler', $content);
    }

    #[Test]
    public function generateHandlesMultipleServices(): void
    {
        $file1 = $this->tempDir . '/GreeterClient.php';
        file_put_contents($file1, <<<'PHP'
            <?php
            namespace Helloworld;
            class GreeterClient extends \Grpc\BaseStub
            {
                public function SayHello(\Helloworld\HelloRequest $argument, $metadata = []) {}
            }
            PHP);

        $file2 = $this->tempDir . '/EchoClient.php';
        file_put_contents($file2, <<<'PHP'
            <?php
            namespace Echo;
            class EchoServiceClient extends \Grpc\BaseStub
            {
                public function Echo(\Echo\EchoRequest $argument, $metadata = []) {}
            }
            PHP);

        $outputDir = $this->tempDir . '/handlers';
        $result = $this->generator->generate([$file1, $file2], $outputDir, 'App\\Handler');

        self::assertCount(2, $result);
    }

    #[Test]
    public function generatedHandlerIncludesMethodDescriptors(): void
    {
        $file = $this->tempDir . '/TestClient.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace MyPackage;
            class TestServiceClient extends \Grpc\BaseStub
            {
                public function DoWork(\MyPackage\WorkRequest $argument, $metadata = []) {}
            }
            PHP);

        $outputDir = $this->tempDir . '/handlers';
        $result = $this->generator->generate([$file], $outputDir, 'App\\Handler');

        self::assertCount(1, $result);

        $content = file_get_contents($result[0]);
        self::assertIsString($content);
        self::assertStringContainsString('MethodDescriptor', $content);
        self::assertStringContainsString('MethodType::Unary', $content);
        self::assertStringContainsString("'DoWork'", $content);
    }

    #[Test]
    public function generatedHandlerIncludesInvokeDispatch(): void
    {
        $file = $this->tempDir . '/SvcClient.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace Pkg;
            class SvcClient extends \Grpc\BaseStub
            {
                public function MethodA(\Pkg\RequestA $argument, $metadata = []) {}
                public function MethodB(\Pkg\RequestB $argument, $metadata = []) {}
            }
            PHP);

        $outputDir = $this->tempDir . '/handlers';
        $result = $this->generator->generate([$file], $outputDir, 'App\\Handler');

        $content = file_get_contents($result[0]);
        self::assertIsString($content);
        self::assertStringContainsString("'MethodA' => \$this->handleMethodA", $content);
        self::assertStringContainsString("'MethodB' => \$this->handleMethodB", $content);
        self::assertStringContainsString('GrpcStatus::Unimplemented', $content);
    }

    #[Test]
    public function generateSkipsNonExistentFiles(): void
    {
        $result = $this->generator->generate(
            ['/nonexistent/file.php'],
            $this->tempDir . '/output',
            'App\\Handler',
        );

        self::assertSame([], $result);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
