<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\ControlVerifier;
use Pulsar\Compliance\VerificationResult;

#[CoversClass(ControlVerifier::class)]
final class ControlVerifierTest extends TestCase
{
    #[Test]
    public function verifyWithRegisteredVerifier(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control(
            id: 'TEST-1',
            framework: 'test',
            title: 'Test Control',
            description: 'A test control',
            status: ControlStatus::Implemented,
        ));

        $verifier = new ControlVerifier($catalog);
        $verifier->registerVerifier('TEST-1', static fn(): VerificationResult => VerificationResult::pass('TEST-1', 'All good'));

        $result = $verifier->verify('TEST-1');

        self::assertTrue($result->passed);
        self::assertSame('All good', $result->message);
    }

    #[Test]
    public function verifyUnknownControlFails(): void
    {
        $catalog = new ControlCatalog();
        $verifier = new ControlVerifier($catalog);

        $result = $verifier->verify('NONEXISTENT');

        self::assertFalse($result->passed);
        self::assertStringContainsString('not found', $result->message);
    }

    #[Test]
    public function verifyWithNoVerifierFails(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control(
            id: 'TEST-1',
            framework: 'test',
            title: 'Test',
            description: 'Test',
            status: ControlStatus::Implemented,
        ));

        $verifier = new ControlVerifier($catalog);
        $result = $verifier->verify('TEST-1');

        self::assertFalse($result->passed);
        self::assertStringContainsString('No verifier', $result->message);
    }

    #[Test]
    public function verifyNotApplicableAlwaysPasses(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control(
            id: 'TEST-NA',
            framework: 'test',
            title: 'Not Applicable',
            description: 'N/A',
            status: ControlStatus::NotApplicable,
        ));

        $verifier = new ControlVerifier($catalog);
        $result = $verifier->verify('TEST-NA');

        self::assertTrue($result->passed);
    }

    #[Test]
    public function verifyAll(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control('T-1', 'test', 'One', 'Test control description', ControlStatus::Implemented));
        $catalog->register(new Control('T-2', 'test', 'Two', 'Test control description', ControlStatus::Implemented));

        $verifier = new ControlVerifier($catalog);
        $verifier->registerVerifier('T-1', static fn(): VerificationResult => VerificationResult::pass('T-1'));
        $verifier->registerVerifier('T-2', static fn(): VerificationResult => VerificationResult::fail('T-2', 'Broken'));

        $results = $verifier->verifyAll();

        self::assertCount(2, $results);
    }

    #[Test]
    public function verifyFrameworkFilters(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control('S-1', 'soc2', 'SOC2', 'Test control description', ControlStatus::Implemented));
        $catalog->register(new Control('H-1', 'hipaa', 'HIPAA', 'Test control description', ControlStatus::Implemented));

        $verifier = new ControlVerifier($catalog);
        $verifier->registerVerifier('S-1', static fn(): VerificationResult => VerificationResult::pass('S-1'));
        $verifier->registerVerifier('H-1', static fn(): VerificationResult => VerificationResult::pass('H-1'));

        $results = $verifier->verifyFramework('soc2');
        self::assertCount(1, $results);
        self::assertSame('S-1', $results[0]->controlId);
    }

    #[Test]
    public function summarize(): void
    {
        $results = [
            VerificationResult::pass('T-1'),
            VerificationResult::pass('T-2'),
            VerificationResult::fail('T-3', 'Broken'),
        ];

        $summary = ControlVerifier::summarize($results);

        self::assertSame(3, $summary['total']);
        self::assertSame(2, $summary['passed']);
        self::assertSame(1, $summary['failed']);
        self::assertEqualsWithDelta(66.7, $summary['pass_rate'], 0.1);
    }

    #[Test]
    public function summarizeEmpty(): void
    {
        $summary = ControlVerifier::summarize([]);

        self::assertSame(0, $summary['total']);
        self::assertSame(0.0, $summary['pass_rate']);
    }
}
