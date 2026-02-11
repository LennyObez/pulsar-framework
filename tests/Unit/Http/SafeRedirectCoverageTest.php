<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\SafeRedirect;

#[CoversClass(SafeRedirect::class)]
final class SafeRedirectCoverageTest extends TestCase
{
    #[Test]
    public function rejectsFtpScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scheme "ftp" is not allowed');

        SafeRedirect::validate('ftp://evil.com/file', ['evil.com']);
    }

    #[Test]
    public function rejectsSshScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not allowed');

        SafeRedirect::validate('ssh://host.com/repo', ['host.com']);
    }
}
