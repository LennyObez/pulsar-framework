<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\OperationOutcome;
use Pulsar\Extension\Fhir\Resource\OperationOutcomeIssue;

#[CoversClass(OperationOutcome::class)]
#[CoversClass(OperationOutcomeIssue::class)]
final class OperationOutcomeTest extends TestCase
{
    #[Test]
    public function errorFactoryCreatesErrorOutcome(): void
    {
        $outcome = OperationOutcome::error('Something went wrong');

        $array = $outcome->toArray();

        self::assertSame('OperationOutcome', $array['resourceType']);
        self::assertCount(1, $array['issue']);
        self::assertSame('error', $array['issue'][0]['severity']);
        self::assertSame('processing', $array['issue'][0]['code']);
        self::assertSame('Something went wrong', $array['issue'][0]['diagnostics']);
    }

    #[Test]
    public function errorFactoryWithCustomCode(): void
    {
        $outcome = OperationOutcome::error('Invalid value', 'invalid');

        $array = $outcome->toArray();

        self::assertSame('invalid', $array['issue'][0]['code']);
    }

    #[Test]
    public function notFoundFactoryCreatesNotFoundOutcome(): void
    {
        $outcome = OperationOutcome::notFound('Patient', 'abc-123');

        $array = $outcome->toArray();

        self::assertCount(1, $array['issue']);
        self::assertSame('error', $array['issue'][0]['severity']);
        self::assertSame('not-found', $array['issue'][0]['code']);
        self::assertSame('Patient/abc-123 not found', $array['issue'][0]['diagnostics']);
    }

    #[Test]
    public function toArrayWithMultipleIssues(): void
    {
        $outcome = new OperationOutcome(
            id: 'oo-1',
            issue: [
                new OperationOutcomeIssue(severity: 'error', code: 'required', diagnostics: 'Missing field'),
                new OperationOutcomeIssue(severity: 'warning', code: 'informational', diagnostics: 'Deprecated field'),
            ],
        );

        $array = $outcome->toArray();

        self::assertSame('oo-1', $array['id']);
        self::assertCount(2, $array['issue']);
        self::assertSame('error', $array['issue'][0]['severity']);
        self::assertSame('warning', $array['issue'][1]['severity']);
    }

    #[Test]
    public function toArrayWithEmptyIssues(): void
    {
        $outcome = new OperationOutcome();

        $array = $outcome->toArray();

        self::assertSame([], $array['issue']);
    }

    #[Test]
    public function issueToArrayMinimal(): void
    {
        $issue = new OperationOutcomeIssue(severity: 'error', code: 'processing');

        $array = $issue->toArray();

        self::assertSame('error', $array['severity']);
        self::assertSame('processing', $array['code']);
        self::assertArrayNotHasKey('details', $array);
        self::assertArrayNotHasKey('diagnostics', $array);
        self::assertArrayNotHasKey('location', $array);
        self::assertArrayNotHasKey('expression', $array);
    }

    #[Test]
    public function issueToArrayFull(): void
    {
        $issue = new OperationOutcomeIssue(
            severity: 'error',
            code: 'invalid',
            diagnostics: 'Value out of range',
            location: ['/Patient/birthDate'],
            expression: ['Patient.birthDate'],
        );

        $array = $issue->toArray();

        self::assertSame('Value out of range', $array['diagnostics']);
        self::assertSame(['/Patient/birthDate'], $array['location']);
        self::assertSame(['Patient.birthDate'], $array['expression']);
    }

    #[Test]
    public function issueFromArrayMinimal(): void
    {
        $issue = OperationOutcomeIssue::fromArray([
            'severity' => 'warning',
            'code' => 'informational',
        ]);

        self::assertSame('warning', $issue->severity);
        self::assertSame('informational', $issue->code);
        self::assertNull($issue->diagnostics);
        self::assertSame([], $issue->location);
    }

    #[Test]
    public function issueFromArrayDefaults(): void
    {
        $issue = OperationOutcomeIssue::fromArray([]);

        self::assertSame('error', $issue->severity);
        self::assertSame('processing', $issue->code);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $original = OperationOutcome::error('Test error', 'processing');
        $array = $original->toArray();
        $restored = OperationOutcome::fromArray($array);

        self::assertSame('OperationOutcome', $restored->toArray()['resourceType']);
        self::assertCount(1, $restored->issue);
        self::assertSame('error', $restored->issue[0]->severity);
        self::assertSame('Test error', $restored->issue[0]->diagnostics);
    }
}
