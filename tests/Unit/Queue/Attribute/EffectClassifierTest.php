<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\AllowNonIdempotent;
use Pulsar\Queue\Attribute\EffectClassification;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\Attribute\Encrypted;
use Pulsar\Queue\Attribute\Idempotent;
use Pulsar\Queue\Attribute\NonIdempotent;
use Pulsar\Queue\Attribute\SideEffectFree;
use Pulsar\Queue\Attribute\SystemJob;
use Pulsar\Queue\Exception\QueueException;

#[Idempotent(key: 'test-key')]
final class IdempotentStubJob {}

#[SideEffectFree]
final class ReadOnlyStubJob {}

#[NonIdempotent(reason: 'Sends payment')]
#[AllowNonIdempotent(reason: 'Approved by compliance', reviewer: 'auditor@example.com')]
final class NonIdempotentStubJob {}

final class NoEffectStubJob {}

#[Idempotent]
#[SideEffectFree]
final class MultipleEffectStubJob {}

#[SystemJob]
#[Idempotent]
final class SystemStubJob {}

#[Encrypted]
#[Idempotent]
final class EncryptedStubJob {}

#[CoversClass(EffectClassifier::class)]
final class EffectClassifierTest extends TestCase
{
    private EffectClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new EffectClassifier();
    }

    #[Test]
    public function classifiesIdempotentJob(): void
    {
        $result = $this->classifier->classify(IdempotentStubJob::class);

        self::assertSame(EffectClassification::Idempotent, $result);
    }

    #[Test]
    public function classifiesReadOnlyJob(): void
    {
        $result = $this->classifier->classify(ReadOnlyStubJob::class);

        self::assertSame(EffectClassification::ReadOnly, $result);
    }

    #[Test]
    public function classifiesNonIdempotentJob(): void
    {
        $result = $this->classifier->classify(NonIdempotentStubJob::class);

        self::assertSame(EffectClassification::NonIdempotent, $result);
    }

    #[Test]
    public function throwsOnMissingClassification(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches('/NoEffectStubJob/');

        $this->classifier->classify(NoEffectStubJob::class);
    }

    #[Test]
    public function throwsOnMultipleClassifications(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches('/multiple effect attributes/');

        $this->classifier->classify(MultipleEffectStubJob::class);
    }

    #[Test]
    public function hasEffectAttributeReturnsTrueWhenPresent(): void
    {
        self::assertTrue($this->classifier->hasEffectAttribute(IdempotentStubJob::class));
        self::assertTrue($this->classifier->hasEffectAttribute(ReadOnlyStubJob::class));
        self::assertTrue($this->classifier->hasEffectAttribute(NonIdempotentStubJob::class));
    }

    #[Test]
    public function hasEffectAttributeReturnsFalseWhenMissing(): void
    {
        self::assertFalse($this->classifier->hasEffectAttribute(NoEffectStubJob::class));
    }

    #[Test]
    public function isSystemJobReturnsTrueForSystemJob(): void
    {
        self::assertTrue($this->classifier->isSystemJob(SystemStubJob::class));
    }

    #[Test]
    public function isSystemJobReturnsFalseForRegularJob(): void
    {
        self::assertFalse($this->classifier->isSystemJob(IdempotentStubJob::class));
    }

    #[Test]
    public function isEncryptedReturnsTrueForEncryptedJob(): void
    {
        self::assertTrue($this->classifier->isEncrypted(EncryptedStubJob::class));
    }

    #[Test]
    public function isEncryptedReturnsFalseForRegularJob(): void
    {
        self::assertFalse($this->classifier->isEncrypted(IdempotentStubJob::class));
    }

    #[Test]
    public function getAllowNonIdempotentReturnsInstanceWhenPresent(): void
    {
        $result = $this->classifier->getAllowNonIdempotent(NonIdempotentStubJob::class);

        self::assertNotNull($result);
        self::assertSame('Approved by compliance', $result->reason);
        self::assertSame('auditor@example.com', $result->reviewer);
    }

    #[Test]
    public function getAllowNonIdempotentReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->classifier->getAllowNonIdempotent(IdempotentStubJob::class));
    }

    #[Test]
    public function requiresSubjectIdReturnsFalseForSystemJob(): void
    {
        self::assertFalse($this->classifier->requiresSubjectId(SystemStubJob::class));
    }

    #[Test]
    public function requiresSubjectIdReturnsTrueForRegularJob(): void
    {
        self::assertTrue($this->classifier->requiresSubjectId(IdempotentStubJob::class));
    }
}
