<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\OverwritePolicy;

#[CoversClass(GeneratedFile::class)]
final class GeneratedFileTest extends TestCase
{
    #[Test]
    public function overwritePolicyDefaultsToFail(): void
    {
        $file = new GeneratedFile(
            targetPath: '/project/src/Models/User.php',
            content: '<?php class User {}',
        );

        self::assertSame(OverwritePolicy::Fail, $file->overwritePolicy);
    }

    /**
     * @return iterable<string, array{OverwritePolicy}>
     */
    public static function overwritePolicyProvider(): iterable
    {
        yield 'skip' => [OverwritePolicy::Skip];
        yield 'force' => [OverwritePolicy::Force];
        yield 'fail' => [OverwritePolicy::Fail];
    }

    #[Test]
    #[DataProvider('overwritePolicyProvider')]
    public function allOverwritePoliciesAreAccepted(OverwritePolicy $policy): void
    {
        $file = new GeneratedFile(
            targetPath: '/path',
            content: 'content',
            overwritePolicy: $policy,
        );

        self::assertSame($policy, $file->overwritePolicy);
    }

    #[Test]
    public function emptyContentIsValid(): void
    {
        $file = new GeneratedFile(targetPath: '/path/empty.php', content: '');

        self::assertSame('', $file->content);
    }
}
