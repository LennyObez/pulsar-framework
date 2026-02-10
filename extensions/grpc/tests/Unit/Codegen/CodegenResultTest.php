<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Grpc\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\CodegenResult;

#[CoversClass(CodegenResult::class)]
final class CodegenResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesSuccessfulResult(): void
    {
        $files = ['/tmp/Foo.php', '/tmp/Bar.php'];
        $result = CodegenResult::success($files, '25.1');

        self::assertTrue($result->success);
        self::assertSame($files, $result->generatedFiles);
        self::assertSame([], $result->errors);
        self::assertSame('25.1', $result->protocVersion);
    }

    #[Test]
    public function failureFactoryCreatesFailedResult(): void
    {
        $errors = ['File not found', 'Syntax error'];
        $result = CodegenResult::failure($errors, '25.1');

        self::assertFalse($result->success);
        self::assertSame([], $result->generatedFiles);
        self::assertSame($errors, $result->errors);
        self::assertSame('25.1', $result->protocVersion);
    }

    #[Test]
    public function failureWithoutVersionUsesEmptyString(): void
    {
        $result = CodegenResult::failure(['error']);

        self::assertFalse($result->success);
        self::assertSame('', $result->protocVersion);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $result = new CodegenResult(
            success: true,
            generatedFiles: ['/tmp/Test.php'],
            errors: ['warning: deprecated'],
            protocVersion: '24.4',
        );

        self::assertTrue($result->success);
        self::assertSame(['/tmp/Test.php'], $result->generatedFiles);
        self::assertSame(['warning: deprecated'], $result->errors);
        self::assertSame('24.4', $result->protocVersion);
    }

    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $result = new CodegenResult(success: false);

        self::assertFalse($result->success);
        self::assertSame([], $result->generatedFiles);
        self::assertSame([], $result->errors);
        self::assertSame('', $result->protocVersion);
    }
}
