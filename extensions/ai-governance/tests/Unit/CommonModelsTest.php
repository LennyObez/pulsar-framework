<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Registry\CommonModels;

#[CoversClass(CommonModels::class)]
#[CoversClass(AiModel::class)]
#[CoversClass(ModelCard::class)]
final class CommonModelsTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): AiModel, non-empty-string, non-empty-string}>
     */
    public static function modelProvider(): iterable
    {
        yield 'GPT-4o' => [CommonModels::gpt4o(...), 'openai-gpt-4o', 'OpenAI'];
        yield 'Claude Opus' => [CommonModels::claudeOpus(...), 'anthropic-claude-opus', 'Anthropic'];
        yield 'Claude Sonnet' => [CommonModels::claudeSonnet(...), 'anthropic-claude-sonnet', 'Anthropic'];
        yield 'Claude Haiku' => [CommonModels::claudeHaiku(...), 'anthropic-claude-haiku', 'Anthropic'];
        yield 'Llama 3.1' => [CommonModels::llama31(...), 'meta-llama-3.1', 'Meta'];
        yield 'Mistral Large' => [CommonModels::mistralLarge(...), 'mistral-large', 'Mistral AI'];
        yield 'Gemini 2.0' => [CommonModels::gemini20(...), 'google-gemini-2.0', 'Google'];
    }

    /**
     * @param callable(): AiModel $factory
     */
    #[Test]
    #[DataProvider('modelProvider')]
    public function factoryReturnsValidModel(callable $factory, string $expectedId, string $expectedProvider): void
    {
        $model = $factory();

        self::assertSame($expectedId, $model->id);
        self::assertSame($expectedProvider, $model->provider);
        self::assertSame('llm', $model->type);
        self::assertSame(AiModelStatus::Production, $model->status);
        self::assertNotNull($model->card);
    }

    /**
     * @param callable(): AiModel $factory
     */
    #[Test]
    #[DataProvider('modelProvider')]
    public function modelCardHasRequiredFields(callable $factory, string $expectedId, string $expectedProvider): void
    {
        $model = $factory();
        $card = $model->card;

        self::assertNotNull($card);
        self::assertNotEmpty($card->description);
        self::assertNotEmpty($card->intendedUse);
        self::assertNotEmpty($card->capabilities);
        self::assertNotEmpty($card->limitations);
        self::assertNotEmpty($card->knownBiases);
        self::assertNotEmpty($card->trainingDataSources);
        self::assertNotEmpty($card->ethicalConsiderations);
    }

    /**
     * @param callable(): AiModel $factory
     */
    #[Test]
    #[DataProvider('modelProvider')]
    public function modelHasNonEmptyNameAndVersion(callable $factory, string $expectedId, string $expectedProvider): void
    {
        $model = $factory();

        self::assertNotEmpty($model->name);
        self::assertNotEmpty($model->version);
    }

    #[Test]
    public function factoryAcceptsCustomRegisteredAt(): void
    {
        $date = new DateTimeImmutable('2025-01-15');
        $model = CommonModels::claudeSonnet($date);

        self::assertSame($date, $model->registeredAt);
    }

    #[Test]
    public function factoryUsesCurrentTimeByDefault(): void
    {
        $before = new DateTimeImmutable();
        $model = CommonModels::gpt4o();
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before->getTimestamp(), $model->registeredAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $model->registeredAt->getTimestamp());
    }

    #[Test]
    public function claudeHaikuHasMinimalRisk(): void
    {
        $model = CommonModels::claudeHaiku();

        self::assertSame(AiModelRiskLevel::Minimal, $model->riskLevel);
    }

    #[Test]
    public function allOtherModelsHaveLimitedRisk(): void
    {
        self::assertSame(AiModelRiskLevel::Limited, CommonModels::gpt4o()->riskLevel);
        self::assertSame(AiModelRiskLevel::Limited, CommonModels::claudeOpus()->riskLevel);
        self::assertSame(AiModelRiskLevel::Limited, CommonModels::claudeSonnet()->riskLevel);
        self::assertSame(AiModelRiskLevel::Limited, CommonModels::llama31()->riskLevel);
        self::assertSame(AiModelRiskLevel::Limited, CommonModels::mistralLarge()->riskLevel);
        self::assertSame(AiModelRiskLevel::Limited, CommonModels::gemini20()->riskLevel);
    }

    #[Test]
    public function allModelIdsAreUnique(): void
    {
        $ids = [
            CommonModels::gpt4o()->id,
            CommonModels::claudeOpus()->id,
            CommonModels::claudeSonnet()->id,
            CommonModels::claudeHaiku()->id,
            CommonModels::llama31()->id,
            CommonModels::mistralLarge()->id,
            CommonModels::gemini20()->id,
        ];

        self::assertSame($ids, array_unique($ids));
    }
}
