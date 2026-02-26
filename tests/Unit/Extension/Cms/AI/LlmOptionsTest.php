<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\LlmOptions;

#[CoversClass(LlmOptions::class)]
final class LlmOptionsTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $options = new LlmOptions();

        self::assertSame(0.7, $options->temperature);
        self::assertSame(1024, $options->maxTokens);
        self::assertNull($options->systemPrompt);
        self::assertSame(30, $options->timeoutSeconds);
        self::assertTrue($options->auditLog);
        self::assertNull($options->maxCostCents);
        self::assertNull($options->usageCategory);
    }

    #[Test]
    public function allFieldsCanBeSet(): void
    {
        $options = new LlmOptions(
            temperature: 0.9,
            maxTokens: 2048,
            systemPrompt: 'You are helpful.',
            timeoutSeconds: 60,
            auditLog: false,
            maxCostCents: 50,
            usageCategory: 'content-generation',
        );

        self::assertSame(0.9, $options->temperature);
        self::assertSame(2048, $options->maxTokens);
        self::assertSame('You are helpful.', $options->systemPrompt);
        self::assertSame(60, $options->timeoutSeconds);
        self::assertFalse($options->auditLog);
        self::assertSame(50, $options->maxCostCents);
        self::assertSame('content-generation', $options->usageCategory);
    }

    #[Test]
    public function partialOverridesPreserveDefaults(): void
    {
        $options = new LlmOptions(
            temperature: 0.5,
            maxTokens: 512,
        );

        self::assertSame(0.5, $options->temperature);
        self::assertSame(512, $options->maxTokens);
        self::assertNull($options->systemPrompt);
        self::assertSame(30, $options->timeoutSeconds);
        self::assertTrue($options->auditLog);
        self::assertNull($options->maxCostCents);
        self::assertNull($options->usageCategory);
    }

    #[Test]
    public function existingCallSiteSignaturePreserved(): void
    {
        // This mirrors how ContentAssistant currently constructs LlmOptions
        $options = new LlmOptions(
            temperature: 0.3,
            maxTokens: 256,
            systemPrompt: 'You are a summarization expert.',
        );

        self::assertSame(0.3, $options->temperature);
        self::assertSame(256, $options->maxTokens);
        self::assertSame('You are a summarization expert.', $options->systemPrompt);
    }

    /**
     * Temperature boundary values in the valid range (0.0–2.0).
     *
     * LlmOptions is a readonly DTO that accepts values as-is — range enforcement
     * is the provider wrapper's responsibility. These tests document the boundary values.
     */
    #[Test]
    #[DataProvider('temperatureBoundaryProvider')]
    public function temperatureBoundaryValuesAreAccepted(float $temperature): void
    {
        $options = new LlmOptions(temperature: $temperature);

        self::assertSame($temperature, $options->temperature);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function temperatureBoundaryProvider(): iterable
    {
        yield 'minimum (0.0)' => [0.0];
        yield 'maximum (2.0)' => [2.0];
        yield 'default (0.7)' => [0.7];
        yield 'creative (1.5)' => [1.5];
    }

    #[Test]
    public function maxTokensPositiveValueIsAccepted(): void
    {
        self::assertSame(1, new LlmOptions(maxTokens: 1)->maxTokens);
    }

    #[Test]
    public function maxTokensLargeValueIsAccepted(): void
    {
        self::assertSame(200_000, new LlmOptions(maxTokens: 200_000)->maxTokens);
    }

    #[Test]
    public function auditLogDefaultsToTrueForRegulatedSystems(): void
    {
        self::assertTrue(new LlmOptions()->auditLog, 'audit logging must be on by default');
        self::assertFalse(new LlmOptions(auditLog: false)->auditLog, 'audit logging can be disabled');
    }

    #[Test]
    public function maxCostCentsLimitsSpend(): void
    {
        // 100 cents = $1.00 per-request budget cap
        self::assertSame(100, new LlmOptions(maxCostCents: 100)->maxCostCents);
        self::assertNull(new LlmOptions()->maxCostCents, 'uncapped by default');
    }

    #[Test]
    public function usageCategoryTagsRequestForBilling(): void
    {
        $options = new LlmOptions(usageCategory: 'seo-meta-generation');

        self::assertSame('seo-meta-generation', $options->usageCategory);
    }
}
