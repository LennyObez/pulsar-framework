<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Certificate\Revocation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\CompositeRevocationChecker;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationCheckerInterface;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationStatus;

#[CoversClass(CompositeRevocationChecker::class)]
final class CompositeRevocationCheckerTest extends TestCase
{
    #[Test]
    public function returnsTheFirstDefinitiveVerdictAndStops(): void
    {
        $composite = new CompositeRevocationChecker(
            $this->fixed(RevocationStatus::Revoked),
            $this->neverCalled('the CRL checker must not run once OCSP is definitive'),
        );

        self::assertSame(RevocationStatus::Revoked, $composite->check('leaf', 'issuer'));
    }

    #[Test]
    public function fallsThroughUnknownToTheNextChecker(): void
    {
        $composite = new CompositeRevocationChecker(
            $this->fixed(RevocationStatus::Unknown),
            $this->fixed(RevocationStatus::Good),
        );

        self::assertSame(RevocationStatus::Good, $composite->check('leaf', 'issuer'));
    }

    #[Test]
    public function returnsUnknownWhenEveryCheckerIsInconclusive(): void
    {
        $composite = new CompositeRevocationChecker(
            $this->fixed(RevocationStatus::Unknown),
            $this->fixed(RevocationStatus::Unknown),
        );

        self::assertSame(RevocationStatus::Unknown, $composite->check('leaf', 'issuer'));
    }

    private function fixed(RevocationStatus $status): RevocationCheckerInterface
    {
        $checker = $this->createStub(RevocationCheckerInterface::class);
        $checker->method('check')->willReturn($status);

        return $checker;
    }

    private function neverCalled(string $message): RevocationCheckerInterface
    {
        return new class ($message) implements RevocationCheckerInterface {
            public function __construct(private readonly string $message) {}

            public function check(string $leafPem, string $issuerPem): RevocationStatus
            {
                TestCase::fail($this->message);
            }
        };
    }
}
