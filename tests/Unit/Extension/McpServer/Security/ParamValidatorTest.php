<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Security\ParamValidator;

#[CoversClass(ParamValidator::class)]
final class ParamValidatorTest extends TestCase
{
    #[Test]
    public function validateClientIdAcceptsValid(): void
    {
        self::assertSame('my-client_01', ParamValidator::validateClientId('my-client_01'));
        self::assertSame('abc', ParamValidator::validateClientId('abc'));
        self::assertSame('A_B-C', ParamValidator::validateClientId('A_B-C'));
    }

    #[Test]
    public function validateClientIdRejectsInvalid(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateClientId('client;rm -rf /');
    }

    #[Test]
    public function validateClientIdRejectsTooLong(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateClientId(str_repeat('a', 65));
    }

    #[Test]
    public function validateFilterAcceptsValid(): void
    {
        self::assertSame('App\\Models\\User', ParamValidator::validateFilter('App\\Models\\User'));
        self::assertSame('route:list', ParamValidator::validateFilter('route:list'));
        self::assertSame('config.app', ParamValidator::validateFilter('config.app'));
        self::assertNull(ParamValidator::validateFilter(null));
    }

    #[Test]
    public function validateFilterRejectsInvalid(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateFilter('$(whoami)');
    }

    #[Test]
    public function validateTypeAcceptsPhpAndJs(): void
    {
        self::assertSame('php', ParamValidator::validateType('php'));
        self::assertSame('js', ParamValidator::validateType('js'));
    }

    #[Test]
    public function validateTypeRejectsOther(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateType('python');
    }

    #[Test]
    public function validateAnalyzerAcceptsPhpstanAndPsalm(): void
    {
        self::assertSame('phpstan', ParamValidator::validateAnalyzer('phpstan'));
        self::assertSame('psalm', ParamValidator::validateAnalyzer('psalm'));
    }

    #[Test]
    public function validateAnalyzerRejectsOther(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateAnalyzer('eslint');
    }

    #[Test]
    public function validatePathRejectsTraversal(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validatePath('../../etc/passwd', '/tmp/project');
    }
}
