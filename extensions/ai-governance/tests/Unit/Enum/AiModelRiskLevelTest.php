<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;

/**
 * The tier docblock cites the EU AI Act, so the tiers have to answer the two
 * questions the Act asks of a classification before anything is deployed.
 */
#[CoversClass(AiModelRiskLevel::class)]
final class AiModelRiskLevelTest extends TestCase
{
    #[Test]
    #[DataProvider('prohibition')]
    public function onlyTheUnacceptableTierIsProhibited(AiModelRiskLevel $tier, bool $prohibited): void
    {
        self::assertSame($prohibited, $tier->isProhibited());
    }

    /**
     * @return iterable<string, array{AiModelRiskLevel, bool}>
     */
    public static function prohibition(): iterable
    {
        yield 'minimal' => [AiModelRiskLevel::Minimal, false];
        yield 'limited' => [AiModelRiskLevel::Limited, false];
        yield 'high' => [AiModelRiskLevel::High, false];
        yield 'unacceptable' => [AiModelRiskLevel::Unacceptable, true];
    }

    #[Test]
    #[DataProvider('highRiskObligations')]
    public function onlyTheHighTierCarriesThePreMarketObligations(
        AiModelRiskLevel $tier,
        bool $carries,
    ): void {
        self::assertSame($carries, $tier->carriesHighRiskObligations());
    }

    /**
     * @return iterable<string, array{AiModelRiskLevel, bool}>
     */
    public static function highRiskObligations(): iterable
    {
        yield 'minimal' => [AiModelRiskLevel::Minimal, false];
        yield 'limited' => [AiModelRiskLevel::Limited, false];
        yield 'high' => [AiModelRiskLevel::High, true];

        // Not "high-risk with an unmet checklist": Article 5 bans the practice,
        // and reporting obligations for it would imply they could be met.
        yield 'unacceptable' => [AiModelRiskLevel::Unacceptable, false];
    }

    #[Test]
    public function theTwoPredicatesAreNeverBothTrue(): void
    {
        foreach (AiModelRiskLevel::cases() as $tier) {
            self::assertFalse(
                $tier->isProhibited() && $tier->carriesHighRiskObligations(),
                "Tier {$tier->value} claims to be both prohibited and conditionally deployable.",
            );
        }
    }
}
