<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\PathValidator;

#[CoversClass(PathValidator::class)]
final class PathValidatorTest extends TestCase
{
    #[Test]
    public function validateAcceptsPathWithinAllowedDirectory(): void
    {
        $validator = new PathValidator('/project');

        $result = $validator->validate('/project/src/Models/User.php');

        self::assertSame('/project/src/Models/User.php', $result);
    }

    #[Test]
    public function validateAcceptsRelativePath(): void
    {
        $validator = new PathValidator('/project');

        $result = $validator->validate('src/Models/User.php');

        self::assertSame('/project/src/Models/User.php', $result);
    }

    #[Test]
    public function validateAcceptsTestsDirectory(): void
    {
        $validator = new PathValidator('/project');

        $result = $validator->validate('tests/Unit/Models/UserTest.php');

        self::assertSame('/project/tests/Unit/Models/UserTest.php', $result);
    }

    #[Test]
    public function validateAcceptsConfigDirectory(): void
    {
        $validator = new PathValidator('/project');

        $result = $validator->validate('config/app.php');

        self::assertSame('/project/config/app.php', $result);
    }

    #[Test]
    public function validateAcceptsMigrationsDirectory(): void
    {
        $validator = new PathValidator('/project');

        $result = $validator->validate('database/migrations/001_create_users.php');

        self::assertSame('/project/database/migrations/001_create_users.php', $result);
    }

    #[Test]
    public function validateRejectsDirectoryTraversal(): void
    {
        $validator = new PathValidator('/project');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('directory traversal');

        $validator->validate('/project/src/../../../etc/passwd');
    }

    #[Test]
    public function validateRejectsPathOutsideAllowlist(): void
    {
        $validator = new PathValidator('/project');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('outside allowed directories');

        $validator->validate('/project/vendor/package/file.php');
    }

    #[Test]
    public function validateRejectsAbsolutePathOutsideProject(): void
    {
        $validator = new PathValidator('/project');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('outside allowed directories');

        $validator->validate('/other/project/src/file.php');
    }

    #[Test]
    public function isAllowedReturnsTrueForValidPath(): void
    {
        $validator = new PathValidator('/project');

        self::assertTrue($validator->isAllowed('src/Models/User.php'));
    }

    #[Test]
    public function isAllowedReturnsFalseForInvalidPath(): void
    {
        $validator = new PathValidator('/project');

        self::assertFalse($validator->isAllowed('vendor/package/file.php'));
    }

    #[Test]
    public function customAllowlistOverridesDefaults(): void
    {
        $validator = new PathValidator('/project', ['app/']);

        self::assertTrue($validator->isAllowed('app/Models/User.php'));
        self::assertFalse($validator->isAllowed('src/Models/User.php'));
    }

    #[Test]
    public function normalizesBackslashesToForwardSlashes(): void
    {
        $validator = new PathValidator('C:/Users/project');

        $result = $validator->validate('C:/Users/project/src/Models/User.php');

        self::assertSame('C:/Users/project/src/Models/User.php', $result);
    }

    #[Test]
    public function windowsAbsolutePathIsRecognized(): void
    {
        $validator = new PathValidator('C:/project');

        $result = $validator->validate('C:/project/src/Models/User.php');

        self::assertSame('C:/project/src/Models/User.php', $result);
    }
}
