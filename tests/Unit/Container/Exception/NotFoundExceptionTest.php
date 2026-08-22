<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Pulsar\Container\Exception\NotFoundException;

#[CoversClass(NotFoundException::class)]
final class NotFoundExceptionTest extends TestCase
{
    #[Test]
    public function implementsPsrNotFoundExceptionInterface(): void
    {
        $exception = NotFoundException::forId('foo');

        self::assertInstanceOf(NotFoundExceptionInterface::class, $exception);
    }

    #[Test]
    public function forIdFormatsMessage(): void
    {
        $exception = NotFoundException::forId('App\\Missing');

        self::assertSame('No binding found for "App\\Missing"', $exception->getMessage());
    }

    #[Test]
    public function forIdWithEmptyString(): void
    {
        $exception = NotFoundException::forId('');

        self::assertSame('No binding found for ""', $exception->getMessage());
    }

    #[Test]
    public function forIdWithInterfaceName(): void
    {
        $exception = NotFoundException::forId('Psr\\Log\\LoggerInterface');

        self::assertStringContainsString('Psr\\Log\\LoggerInterface', $exception->getMessage());
    }
}
