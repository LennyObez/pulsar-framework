<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Security\ParamValidator;

final class ParamValidatorTest extends TestCase
{
    #[Test]
    public function validateClientIdAcceptsValidId(): void
    {
        $result = ParamValidator::validateClientId('my-client_01');

        self::assertSame('my-client_01', $result);
    }

    #[Test]
    public function validateClientIdRejectsInvalidCharacters(): void
    {
        $this->expectException(McpException::class);
        ParamValidator::validateClientId('client@invalid!');
    }

    #[Test]
    public function validateClientIdRejectsTooLongId(): void
    {
        $this->expectException(McpException::class);
        ParamValidator::validateClientId(str_repeat('a', 65));
    }

    #[Test]
    public function validateFilterReturnsNullForNull(): void
    {
        self::assertNull(ParamValidator::validateFilter(null));
    }

    #[Test]
    public function validateFilterAcceptsValidFilter(): void
    {
        $result = ParamValidator::validateFilter('App\\Controller::index');

        self::assertSame('App\\Controller::index', $result);
    }

    #[Test]
    public function validateFilterRejectsTooLong(): void
    {
        $this->expectException(McpException::class);
        ParamValidator::validateFilter(str_repeat('A', 257));
    }

    #[Test]
    public function validateFilterRejectsInvalidCharacters(): void
    {
        $this->expectException(McpException::class);
        ParamValidator::validateFilter('filter with spaces');
    }

    #[Test]
    public function validatePathReturnsNullForNull(): void
    {
        self::assertNull(ParamValidator::validatePath(null, '/app'));
    }

    #[Test]
    public function validatePathRejectsTraversal(): void
    {
        $this->expectException(McpException::class);
        ParamValidator::validatePath('../../etc/passwd', '/app');
    }

    #[Test]
    public function validateTypeAcceptsPhp(): void
    {
        self::assertSame('php', ParamValidator::validateType('php'));
    }

    #[Test]
    public function validateTypeAcceptsJs(): void
    {
        self::assertSame('js', ParamValidator::validateType('js'));
    }

    #[Test]
    public function validateTypeRejectsOther(): void
    {
        $this->expectException(McpException::class);
        ParamValidator::validateType('python');
    }

    #[Test]
    public function validateAnalyzerAcceptsPhpstan(): void
    {
        self::assertSame('phpstan', ParamValidator::validateAnalyzer('phpstan'));
    }

    #[Test]
    public function validateAnalyzerAcceptsPsalm(): void
    {
        self::assertSame('psalm', ParamValidator::validateAnalyzer('psalm'));
    }

    #[Test]
    public function validateAnalyzerRejectsOther(): void
    {
        $this->expectException(McpException::class);
        ParamValidator::validateAnalyzer('eslint');
    }
}
