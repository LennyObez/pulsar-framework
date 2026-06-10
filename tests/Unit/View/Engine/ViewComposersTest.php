<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\ViewComposers;
use Pulsar\View\Engine\ViewContext;

use function is_string;

/**
 * Behavioural tests for the shared-data / view-composer resolver.
 *
 * Every test goes through the real {@see ViewComposers} (and real Fibers for
 * the isolation tests) — no part of the unit under test is stubbed. Each
 * assertion checks an output the implementation has to COMPUTE (merge order,
 * memoisation counts, per-Fiber state), never a value the test merely set.
 */
#[CoversClass(ViewComposers::class)]
#[CoversClass(ViewContext::class)]
final class ViewComposersTest extends TestCase
{
    #[Test]
    public function sharedDataIsMergedIntoResolvedData(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $composers->share('foo', 1);

        // Act
        $resolved = $composers->resolve('any.template', []);

        // Assert
        self::assertSame(1, $resolved['foo']);
    }

    #[Test]
    public function bulkShareMergesAllPairs(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $composers->share(['a' => 1, 'b' => 2]);

        // Act
        $resolved = $composers->resolve('any.template', []);

        // Assert
        self::assertSame(1, $resolved['a']);
        self::assertSame(2, $resolved['b']);
    }

    #[Test]
    public function explicitRenderDataWinsOverSharedDataOnKeyCollision(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $composers->share('foo', 1);

        // Act
        $resolved = $composers->resolve('any.template', ['foo' => 2]);

        // Assert
        self::assertSame(2, $resolved['foo']);
    }

    #[Test]
    public function precedenceIsSharedThenComposerThenExplicit(): void
    {
        // Arrange — the same key set at all three tiers, plus one unique key per tier
        $composers = new ViewComposers();
        $composers->share(['contested' => 'shared', 'onlyShared' => 'S']);
        $composers->composer('*', static fn(): array => ['contested' => 'composer', 'onlyComposer' => 'C']);

        // Act
        $resolved = $composers->resolve('any.template', ['contested' => 'explicit', 'onlyExplicit' => 'E']);

        // Assert — collision resolves low→high: shared → composer → explicit
        self::assertSame('explicit', $resolved['contested']);
        self::assertSame('S', $resolved['onlyShared']);
        self::assertSame('C', $resolved['onlyComposer']);
        self::assertSame('E', $resolved['onlyExplicit']);
    }

    #[Test]
    public function laterComposerOverridesEarlierForTheSameKey(): void
    {
        // Arrange — two wildcard composers contesting one key
        $composers = new ViewComposers();
        $composers->composer('*', static fn(): array => ['k' => 'first']);
        $composers->composer('*', static fn(): array => ['k' => 'second']);

        // Act
        $resolved = $composers->resolve('any.template', []);

        // Assert — registration order wins (later overrides earlier)
        self::assertSame('second', $resolved['k']);
    }

    #[Test]
    public function composerRunsAtMostOncePerRequestEvenWhenSeveralTemplatesMatch(): void
    {
        // Arrange — an invocation-counting composer matching three partials
        $composers = new ViewComposers();
        $calls = 0;
        $composers->composer('theme.partials.*', static function () use (&$calls): array {
            ++$calls;

            return ['nav' => 'NAV'];
        });

        // Act — header, footer and drawer all resolve in the same request
        $header = $composers->resolve('theme.partials.header', []);
        $footer = $composers->resolve('theme.partials.footer', []);
        $drawer = $composers->resolve('theme.partials.drawer', []);

        // Assert — every partial received the data, but the builder ran once
        self::assertSame('NAV', $header['nav']);
        self::assertSame('NAV', $footer['nav']);
        self::assertSame('NAV', $drawer['nav']);
        self::assertSame(1, $calls);
    }

    #[Test]
    public function globPatternsSelectWhichTemplatesAComposerAppliesTo(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $composers->composer('errors.*', static fn(): array => ['e' => true]);
        $composers->composer('exact.name', static fn(): array => ['x' => true]);

        // Act
        $matchingGlob = $composers->resolve('errors.404', []);
        $nonMatching = $composers->resolve('home', []);
        $matchingExact = $composers->resolve('exact.name', []);
        $siblingOfExact = $composers->resolve('exact.other', []);

        // Assert
        self::assertArrayHasKey('e', $matchingGlob);
        self::assertArrayNotHasKey('e', $nonMatching);
        self::assertArrayHasKey('x', $matchingExact);
        self::assertArrayNotHasKey('x', $siblingOfExact);
    }

    #[Test]
    public function wildcardComposerAppliesToEveryTemplate(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $composers->composer('*', static fn(): array => ['global' => 1]);

        // Act
        $resolved = $composers->resolve('deeply.nested.partial', []);

        // Assert
        self::assertSame(1, $resolved['global']);
    }

    #[Test]
    public function patternListMatchesWhenAnyPatternMatches(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $composers->composer(['errors.*', 'theme.partials.*'], static fn(): array => ['m' => 1]);

        // Act
        $viaFirst = $composers->resolve('errors.500', []);
        $viaSecond = $composers->resolve('theme.partials.header', []);
        $neither = $composers->resolve('home', []);

        // Assert
        self::assertArrayHasKey('m', $viaFirst);
        self::assertArrayHasKey('m', $viaSecond);
        self::assertArrayNotHasKey('m', $neither);
    }

    #[Test]
    public function composerMayContributeThroughContextWithInsteadOfReturning(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $composers->composer('*', static function (ViewContext $ctx): void {
            $ctx->with('viaCtx', 'yes');
        });

        // Act
        $resolved = $composers->resolve('any.template', []);

        // Assert
        self::assertSame('yes', $resolved['viaCtx']);
    }

    #[Test]
    public function returnedArrayWinsOverContextWithForTheSameKey(): void
    {
        // Arrange — one composer contesting a key through both channels
        $composers = new ViewComposers();
        $composers->composer('*', static function (ViewContext $ctx): array {
            $ctx->with('k', 'via-with');

            return ['k' => 'via-return'];
        });

        // Act
        $resolved = $composers->resolve('any.template', []);

        // Assert — the documented channel precedence: returned array overrides with()
        self::assertSame('via-return', $resolved['k']);
    }

    #[Test]
    public function composerReadsDataResolvedSoFarThroughTheContext(): void
    {
        // Arrange — shared data feeds a composer that derives a value from it
        $composers = new ViewComposers();
        $composers->share('locale', 'fr');
        $composers->composer('*', static function (ViewContext $ctx): array {
            $locale = $ctx->get('locale');

            return [
                'derived' => (is_string($locale) ? $locale : 'en') . '-derived',
                'missing' => $ctx->get('absent', 'fallback'),
                'hasLocale' => $ctx->has('locale'),
            ];
        });

        // Act
        $resolved = $composers->resolve('any.template', []);

        // Assert — the composer computed from upstream state, not from constants
        self::assertSame('fr-derived', $resolved['derived']);
        self::assertSame('fallback', $resolved['missing']);
        self::assertTrue($resolved['hasLocale']);
    }

    #[Test]
    public function composerReceivesTheNameOfTheTemplateBeingRendered(): void
    {
        // Arrange
        $composers = new ViewComposers();
        $seen = [];
        $composers->composer('*', static function (ViewContext $ctx) use (&$seen): array {
            $seen[] = $ctx->template;

            return [];
        });

        // Act
        $composers->resolve('theme.partials.header', []);

        // Assert
        self::assertSame(['theme.partials.header'], $seen);
    }

    #[Test]
    public function resetRequestStateClearsSharedDataAndReArmsComposers(): void
    {
        // Arrange — request 1 shares data and memoises one composer run
        $composers = new ViewComposers();
        $calls = 0;
        $composers->share('x', 1);
        $composers->composer('*', static function () use (&$calls): array {
            ++$calls;

            return ['c' => 1];
        });
        $composers->resolve('any.template', []);

        // Act — boundary between two sequential requests on the same worker
        $composers->resetRequestState();
        $afterReset = $composers->resolve('any.template', []);

        // Assert — shared data gone, composer ran again (memo cleared)
        self::assertArrayNotHasKey('x', $afterReset);
        self::assertSame(2, $calls);
    }

    #[Test]
    public function sharedDataAndMemoAreIsolatedPerFiber(): void
    {
        // Arrange — root scope shares one value
        $composers = new ViewComposers();
        $composers->share('who', 'root');

        // Act — a concurrent Fiber writes the same key and resolves inside itself
        $fiber = new Fiber(static function () use ($composers): void {
            $composers->share('who', 'fiber');
            Fiber::suspend($composers->resolve('any.template', []));
        });
        /** @var array<string, mixed> $insideFiber */
        $insideFiber = $fiber->start();
        $atRoot = $composers->resolve('any.template', []);

        // Assert — no bleed in either direction
        self::assertSame('fiber', $insideFiber['who']);
        self::assertSame('root', $atRoot['who']);
    }

    #[Test]
    public function resolveReturnsExplicitDataUntouchedWhenNothingIsRegistered(): void
    {
        // Arrange — the hot-path case: no shares, no composers
        $composers = new ViewComposers();
        $explicit = ['only' => 'explicit'];

        // Act
        $resolved = $composers->resolve('any.template', $explicit);

        // Assert — identical array back (fast path adds nothing)
        self::assertSame($explicit, $resolved);
    }
}
