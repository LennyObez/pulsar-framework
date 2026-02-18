<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
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
}
