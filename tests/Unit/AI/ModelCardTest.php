<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ModelCard;

use function count;

#[CoversClass(ModelCard::class)]
final class ModelCardTest extends TestCase
{
    #[Test]
    public function lookupReturnsKnownModel(): void
    {
        $card = ModelCard::lookup('claude-sonnet-4-6');

        self::assertNotNull($card);
        self::assertSame('claude-sonnet-4-6', $card->id);
        self::assertSame('anthropic', $card->provider);
        self::assertSame(200_000, $card->contextWindow);
        self::assertTrue($card->supportsVision);
        self::assertTrue($card->supportsToolCalling);
        self::assertTrue($card->supportsStructuredOutput);
    }

    #[Test]
    public function lookupReturnsNullForUnknownModel(): void
    {
        self::assertNull(ModelCard::lookup('nonexistent-model'));
    }

    #[Test]
    #[DataProvider('registeredModelProvider')]
    public function registeredModelsHaveValidMetadata(string $modelId, string $provider, int $minContext): void
    {
        $card = ModelCard::lookup($modelId);

        self::assertNotNull($card);
        self::assertSame($provider, $card->provider);
        self::assertGreaterThanOrEqual($minContext, $card->contextWindow);
        self::assertSame($modelId, $card->id);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function registeredModelProvider(): iterable
    {
        yield 'claude-sonnet-4-6' => ['claude-sonnet-4-6', 'anthropic', 200_000];
        yield 'claude-opus-4-6' => ['claude-opus-4-6', 'anthropic', 200_000];
        yield 'claude-haiku-3-5' => ['claude-haiku-3-5', 'anthropic', 200_000];
        yield 'gpt-4o' => ['gpt-4o', 'openai', 128_000];
        yield 'gpt-4o-mini' => ['gpt-4o-mini', 'openai', 128_000];
        yield 'o3' => ['o3', 'openai', 200_000];
        yield 'o4-mini' => ['o4-mini', 'openai', 200_000];
    }

    #[Test]
    public function embeddingModelsHaveDimensions(): void
    {
        $small = ModelCard::lookup('text-embedding-3-small');

        self::assertNotNull($small);
        self::assertTrue($small->supportsEmbeddings);
        self::assertSame(1536, $small->embeddingDimensions);

        $large = ModelCard::lookup('text-embedding-3-large');

        self::assertNotNull($large);
        self::assertSame(3072, $large->embeddingDimensions);
    }

    #[Test]
    public function allReturnsNonEmptyRegistry(): void
    {
        $all = ModelCard::all();

        self::assertNotEmpty($all);
        self::assertGreaterThanOrEqual(9, count($all));

        foreach ($all as $id => $card) {
            self::assertSame($id, $card->id);
            self::assertNotEmpty($card->provider);
        }
    }

    #[Test]
    public function chatModelsDoNotAdvertiseEmbeddings(): void
    {
        $claude = ModelCard::lookup('claude-sonnet-4-6');

        self::assertNotNull($claude);
        self::assertFalse($claude->supportsEmbeddings);
        self::assertSame(0, $claude->embeddingDimensions);
    }
}
