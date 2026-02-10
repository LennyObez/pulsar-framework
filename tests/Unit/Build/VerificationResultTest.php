<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\VerificationResult;
use Pulsar\Build\VerificationStatus;

#[CoversClass(VerificationResult::class)]
final class VerificationResultTest extends TestCase
{
    #[Test]
    public function passFactoryCreatesPassingResult(): void
    {
        $entries = [
            'extensions' => VerificationStatus::Ok,
            'routes' => VerificationStatus::Ok,
        ];

        $result = VerificationResult::pass($entries);

        self::assertTrue($result->passed);
        self::assertSame($entries, $result->entries);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function failFactoryCreatesFailingResult(): void
    {
        $entries = [
            'extensions' => VerificationStatus::Ok,
            'routes' => VerificationStatus::Modified,
        ];

        $errors = ['Artifact "routes" has been modified'];

        $result = VerificationResult::fail($entries, $errors);

        self::assertFalse($result->passed);
        self::assertSame($entries, $result->entries);
        self::assertSame($errors, $result->errors);
    }

    #[Test]
    public function toArrayExportsAllFields(): void
    {
        $result = VerificationResult::fail(
            ['ext' => VerificationStatus::Missing],
            ['Artifact "ext" is missing'],
        );

        $array = $result->toArray();

        self::assertFalse($array['passed']);
        self::assertSame(['ext' => 'missing'], $array['entries']);
        self::assertSame(['Artifact "ext" is missing'], $array['errors']);
    }

    #[Test]
    public function fromArrayToArrayRoundTrip(): void
    {
        $data = [
            'passed' => true,
            'entries' => [
                'container' => 'ok',
                'extensions' => 'ok',
            ],
            'errors' => [],
        ];

        $result = VerificationResult::fromArray($data);
        $exported = $result->toArray();

        self::assertSame($data, $exported);
    }

    #[Test]
    public function fromArrayHandlesAllStatusValues(): void
    {
        $data = [
            'passed' => false,
            'entries' => [
                'ok_artifact' => 'ok',
                'modified_artifact' => 'modified',
                'missing_artifact' => 'missing',
                'extra_artifact' => 'extra',
            ],
            'errors' => ['some error'],
        ];

        $result = VerificationResult::fromArray($data);

        self::assertSame(VerificationStatus::Ok, $result->entries['ok_artifact']);
        self::assertSame(VerificationStatus::Modified, $result->entries['modified_artifact']);
        self::assertSame(VerificationStatus::Missing, $result->entries['missing_artifact']);
        self::assertSame(VerificationStatus::Extra, $result->entries['extra_artifact']);
    }

    #[Test]
    public function fromArrayIgnoresInvalidStatusValues(): void
    {
        $data = [
            'passed' => true,
            'entries' => [
                'valid' => 'ok',
                'invalid' => 'not_a_real_status',
            ],
            'errors' => [],
        ];

        $result = VerificationResult::fromArray($data);

        self::assertCount(1, $result->entries);
        self::assertArrayHasKey('valid', $result->entries);
        self::assertArrayNotHasKey('invalid', $result->entries);
    }
}
