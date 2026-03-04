<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\AI;

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
    public function constructWithCustomValues(): void
    {
        $options = new LlmOptions(
            temperature: 0.3,
            maxTokens: 4096,
            systemPrompt: 'You are a helpful assistant.',
            timeoutSeconds: 60,
            auditLog: false,
            maxCostCents: 10,
            usageCategory: 'content-generation',
        );

        self::assertSame(0.3, $options->temperature);
        self::assertSame(4096, $options->maxTokens);
        self::assertSame('You are a helpful assistant.', $options->systemPrompt);
        self::assertSame(60, $options->timeoutSeconds);
        self::assertFalse($options->auditLog);
        self::assertSame(10, $options->maxCostCents);
        self::assertSame('content-generation', $options->usageCategory);
    }
}
