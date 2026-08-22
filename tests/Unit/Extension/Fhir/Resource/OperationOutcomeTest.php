<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\OperationOutcome;
use Pulsar\Extension\Fhir\Resource\OperationOutcomeIssue;

#[CoversClass(OperationOutcome::class)]
#[CoversClass(OperationOutcomeIssue::class)]
final class OperationOutcomeTest extends TestCase
{
    public function testErrorFactory(): void
    {
        $outcome = OperationOutcome::error('Something went wrong');

        $array = $outcome->toArray();

        self::assertSame('OperationOutcome', $array['resourceType']);
        self::assertIsArray($array['issue']);
        self::assertCount(1, $array['issue']);
        $issue0 = $array['issue'][0];
        self::assertIsArray($issue0);
        self::assertSame('error', $issue0['severity']);
        self::assertSame('processing', $issue0['code']);
        self::assertSame('Something went wrong', $issue0['diagnostics']);
    }

    public function testNotFoundFactory(): void
    {
        $outcome = OperationOutcome::notFound('Patient', 'pt-999');

        $array = $outcome->toArray();

        self::assertIsArray($array['issue']);
        self::assertCount(1, $array['issue']);
        $issue0 = $array['issue'][0];
        self::assertIsArray($issue0);
        self::assertSame('not-found', $issue0['code']);
        self::assertSame('Patient/pt-999 not found', $issue0['diagnostics']);
    }

    public function testIssueWithExpression(): void
    {
        $issue = new OperationOutcomeIssue(
            severity: 'warning',
            code: 'invariant',
            diagnostics: 'Name should have family',
            expression: ['Patient.name'],
        );

        $array = $issue->toArray();
        self::assertSame('warning', $array['severity']);
        self::assertSame(['Patient.name'], $array['expression']);
    }

    public function testFromArray(): void
    {
        $data = [
            'resourceType' => 'OperationOutcome',
            'issue' => [
                ['severity' => 'error', 'code' => 'invalid', 'diagnostics' => 'Bad input'],
                ['severity' => 'warning', 'code' => 'informational', 'diagnostics' => 'Check field'],
            ],
        ];

        $outcome = OperationOutcome::fromArray($data);
        self::assertCount(2, $outcome->issue);
        self::assertSame('error', $outcome->issue[0]->severity);
        self::assertSame('warning', $outcome->issue[1]->severity);
    }
}
