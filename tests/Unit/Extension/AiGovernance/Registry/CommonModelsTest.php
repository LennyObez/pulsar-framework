<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\AiGovernance\Registry;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Registry\CommonModels;

use function count;

#[CoversClass(CommonModels::class)]
final class CommonModelsTest extends TestCase
{
    #[Test]
    #[DataProvider('modelFactoryProvider')]
    public function factoryReturnsValidModel(string $method, string $expectedId, string $expectedProvider): void
    {
        /** @var AiModel $model */
        $model = CommonModels::$method();

        self::assertSame($expectedId, $model->id);
        self::assertSame($expectedProvider, $model->provider);
        self::assertSame(AiModelStatus::Production, $model->status);
        self::assertNotNull($model->card);
        self::assertNotEmpty($model->card->description);
        self::assertNotEmpty($model->card->intendedUse);
        self::assertNotEmpty($model->card->capabilities);
        self::assertNotEmpty($model->card->limitations);
        self::assertNotEmpty($model->card->knownBiases);
        self::assertNotEmpty($model->card->ethicalConsiderations);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function modelFactoryProvider(): iterable
    {
        yield 'GPT-4o' => ['gpt4o', 'openai-gpt-4o', 'OpenAI'];
        yield 'Claude Opus' => ['claudeOpus', 'anthropic-claude-opus', 'Anthropic'];
        yield 'Claude Sonnet' => ['claudeSonnet', 'anthropic-claude-sonnet', 'Anthropic'];
        yield 'Claude Haiku' => ['claudeHaiku', 'anthropic-claude-haiku', 'Anthropic'];
        yield 'Llama 3.1' => ['llama31', 'meta-llama-3.1', 'Meta'];
        yield 'Mistral Large' => ['mistralLarge', 'mistral-large', 'Mistral AI'];
        yield 'Gemini 2.0' => ['gemini20', 'google-gemini-2.0', 'Google'];
    }

    #[Test]
    public function factoriesAcceptCustomRegisteredAt(): void
    {
        $date = new DateTimeImmutable('2025-06-01');

        $model = CommonModels::claudeSonnet($date);

        self::assertSame($date, $model->registeredAt);
    }

    #[Test]
    public function factoriesDefaultToCurrentTimestamp(): void
    {
        $before = new DateTimeImmutable();
        $model = CommonModels::gpt4o();
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $model->registeredAt);
        self::assertLessThanOrEqual($after, $model->registeredAt);
    }

    #[Test]
    public function allModelsHaveUniqueIds(): void
    {
        $ids = [];
        $methods = ['gpt4o', 'claudeOpus', 'claudeSonnet', 'claudeHaiku', 'llama31', 'mistralLarge', 'gemini20'];

        foreach ($methods as $method) {
            /** @var AiModel $model */
            $model = CommonModels::$method();
            $ids[] = $model->id;
        }

        self::assertCount(count($methods), array_unique($ids));
    }
}
