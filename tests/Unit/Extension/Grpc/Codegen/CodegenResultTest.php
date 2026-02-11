<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\CodegenResult;

#[CoversClass(CodegenResult::class)]
final class CodegenResultTest extends TestCase
{
    #[Test]
    public function successFactory(): void
    {
        $result = CodegenResult::success(
            generatedFiles: ['/path/a.php', '/path/b.php'],
            protocVersion: '3.25.0',
        );

        self::assertTrue($result->success);
        self::assertSame(['/path/a.php', '/path/b.php'], $result->generatedFiles);
        self::assertSame([], $result->errors);
        self::assertSame('3.25.0', $result->protocVersion);
    }

    #[Test]
    public function failureFactory(): void
    {
        $result = CodegenResult::failure(
            errors: ['File not found', 'Syntax error'],
            protocVersion: '3.24.0',
        );

        self::assertFalse($result->success);
        self::assertSame([], $result->generatedFiles);
        self::assertSame(['File not found', 'Syntax error'], $result->errors);
        self::assertSame('3.24.0', $result->protocVersion);
    }

    #[Test]
    public function failureFactoryWithoutVersion(): void
    {
        $result = CodegenResult::failure(errors: ['error']);

        self::assertFalse($result->success);
        self::assertSame('', $result->protocVersion);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $result = new CodegenResult(success: true);

        self::assertTrue($result->success);
        self::assertSame([], $result->generatedFiles);
        self::assertSame([], $result->errors);
        self::assertSame('', $result->protocVersion);
    }
}
