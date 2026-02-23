<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\OverwritePolicy;

#[CoversClass(GeneratedFile::class)]
final class GeneratedFileTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $file = new GeneratedFile(
            targetPath: '/project/src/Models/User.php',
            content: '<?php class User {}',
            overwritePolicy: OverwritePolicy::Force,
        );

        self::assertSame('/project/src/Models/User.php', $file->targetPath);
        self::assertSame('<?php class User {}', $file->content);
        self::assertSame(OverwritePolicy::Force, $file->overwritePolicy);
    }

    #[Test]
    public function overwritePolicyDefaultsToFail(): void
    {
        $file = new GeneratedFile(
            targetPath: '/project/src/Models/User.php',
            content: '<?php class User {}',
        );

        self::assertSame(OverwritePolicy::Fail, $file->overwritePolicy);
    }
}
