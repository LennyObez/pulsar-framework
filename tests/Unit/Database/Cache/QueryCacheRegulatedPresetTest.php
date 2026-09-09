<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\QueryCacheConfig;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Database\Cache\Support\DocumentedQueryCacheDefaults;
use RuntimeException;

use function file_get_contents;
use function implode;

/**
 * The regulated preset, watched selecting the posture it claims to select.
 *
 * WHAT WAS HERE BEFORE. `QueryCacheConfigTest::regulatedPresetDefaultsDisabled()`
 * passed `'enabled' => false` and asserted that `enabled` was false. It named the
 * regulated preset in its own method name and tested nothing about it: it would
 * have passed identically on a tree in which no preset existed -- which is the
 * tree it was actually running on, because none did. The sentence "caching is
 * disabled by default in regulated environments" was written in the DTO's
 * docblock, in config/database.php and in docs/database.md, and implemented in
 * none of them.
 *
 * So the input the claim is about is planted here instead -- a regulated preset
 * with no `enabled` key -- and the refusal to cache is observed. On the code as it
 * stood, {@see itDisablesCachingWhenTheRegulatedPresetIsSetAndNobodySaidOtherwise}
 * fails: `fromArray()` returned `enabled: true` for that input, and every
 * regulated deployment that trusted the sentence was caching.
 *
 * The two control cases matter as much as the planted one. Without
 * {@see itLeavesCachingOnWhenNoPresetIsDeclared} a DTO that returned `false`
 * unconditionally would satisfy the assertion above while breaking every
 * application that already runs on this framework, and without
 * {@see itLetsAnExplicitEnabledWinUnderTheRegulatedPreset} the preset would be a
 * ban rather than a default -- a setting nobody can reach is not the feature the
 * documentation describes.
 */
#[CoversClass(QueryCacheConfig::class)]
#[GuardsGate(gate: 'QueryCacheRegulatedPresetTest::theDocumentedTruthTableMatchesTheCode', plants: 'a documented truth table row claiming caching is on where the regulated preset turns it off, and a document whose table has been deleted so every row-driven assertion would pass over nothing')]
final class QueryCacheRegulatedPresetTest extends TestCase
{
    private const string DOCUMENT = __DIR__ . '/../../../../docs/database.md';

    private const string SHIPPED_CONFIG = __DIR__ . '/../../../../config/database.php';

    /**
     * The planted defect: the exact input the three sentences describe.
     */
    #[Test]
    public function itDisablesCachingWhenTheRegulatedPresetIsSetAndNobodySaidOtherwise(): void
    {
        $config = QueryCacheConfig::fromArray(['regulated_preset' => true]);

        self::assertTrue($config->regulatedPreset);
        self::assertFalse(
            $config->enabled,
            'a regulated deployment that wrote down no `enabled` key got query caching switched ON. '
            . 'What ships on that silence: a result row read under the authorization of one caller served '
            . 'from cache to the next, in a framework whose stated audience is banking, healthcare and '
            . 'legal work -- while the DTO docblock, config/database.php and docs/database.md all say '
            . 'the opposite is happening.',
        );
    }

    /**
     * The control that stops the assertion above from being satisfied by a DTO
     * that simply returns false.
     */
    #[Test]
    public function itLeavesCachingOnWhenNoPresetIsDeclared(): void
    {
        self::assertTrue(QueryCacheConfig::fromArray([])->enabled);
        self::assertTrue(QueryCacheConfig::fromArray(['regulated_preset' => false])->enabled);
        self::assertFalse(QueryCacheConfig::fromArray([])->regulatedPreset);
    }

    /**
     * "Disabled by default" is a default, not a ban.
     */
    #[Test]
    public function itLetsAnExplicitEnabledWinUnderTheRegulatedPreset(): void
    {
        self::assertTrue(QueryCacheConfig::fromArray([
            'regulated_preset' => true,
            'enabled' => true,
        ])->enabled);

        self::assertFalse(QueryCacheConfig::fromArray([
            'regulated_preset' => false,
            'enabled' => false,
        ])->enabled);
    }

    /**
     * A preset that is not a bool is not an answer, and the non-answer is the
     * posture the rest of the tree already has.
     *
     * `'regulated_preset' => 'false'` is what an operator writes when they mean
     * the opposite of what a cast would read, and `1` is what an env-backed value
     * arrives as. Neither is a decision this setting can act on, and neither may
     * silently move a deployment: the value the operator did not manage to write
     * leaves `enabled` where it was.
     */
    #[Test]
    public function itRefusesToReadANonBooleanPresetAsADecision(): void
    {
        /** @var list<mixed> $candidates */
        $candidates = ['false', 'true', 1, 0, null, []];

        foreach ($candidates as $written) {
            $config = QueryCacheConfig::fromArray(['regulated_preset' => $written]);

            self::assertFalse($config->regulatedPreset);
            self::assertTrue($config->enabled);
        }
    }

    /**
     * An `enabled` key that IS written down keeps the reading every deployment
     * on rc.11 and earlier already got.
     *
     * Narrowing this to a strict bool would turn `'enabled' => 0` into the
     * default, which under a non-regulated preset means switching caching ON for
     * an application that had switched it off. The preset adds a posture; it does
     * not re-read the settings that were already there.
     */
    #[Test]
    public function itKeepsTheExistingReadingOfAWrittenDownEnabledKey(): void
    {
        self::assertFalse(QueryCacheConfig::fromArray(['enabled' => 0])->enabled);
        self::assertFalse(QueryCacheConfig::fromArray(['enabled' => ''])->enabled);
        self::assertTrue(QueryCacheConfig::fromArray(['enabled' => 1])->enabled);
    }

    /**
     * `regulated_preset` is a key the DTO reads, so it must not be reported as one
     * it does not.
     *
     * The unknown-key report is a cache-poisoning guard: a misspelled
     * `sensitive_table_names` silently makes sensitive tables cacheable. A new key
     * left out of KNOWN_KEYS would be reported as a typo on every boot, and a
     * guard that cries wolf on a correct configuration is a guard operators learn
     * to ignore.
     */
    #[Test]
    public function itDoesNotReportItsOwnPresetKeyAsUnknown(): void
    {
        self::assertSame([], QueryCacheConfig::fromArray(['regulated_preset' => true])->unknownConfigKeys());
        self::assertSame(
            ['regulated_presets'],
            QueryCacheConfig::fromArray(['regulated_presets' => true])->unknownConfigKeys(),
        );
    }

    /**
     * The shipped configuration file carries the key, at the value it documents.
     *
     * config/model_binding.php and its DTO are pinned to each other for the same
     * reason: a shipped file and the code that reads it are two statements of one
     * fact, and the file is the one a reviewer reads.
     */
    #[Test]
    public function theShippedConfigurationDeclaresThePreset(): void
    {
        /** @var mixed $document */
        $document = require self::SHIPPED_CONFIG;

        self::assertIsArray($document);
        self::assertArrayHasKey('query_cache', $document);

        /** @var mixed $section */
        $section = $document['query_cache'];
        self::assertIsArray($section);
        self::assertArrayHasKey(
            'regulated_preset',
            $section,
            'config/database.php documents the regulated preset in a comment but does not ship the '
            . 'key, so an operator following the comment writes it into a file where nothing lists '
            . 'it and the DTO reports it as unknown.',
        );
        self::assertFalse(
            $section['regulated_preset'],
            'the shipped default switches an existing deployment away from the caching posture it '
            . 'already runs on. That decision belongs to the operator, not to an upgrade.',
        );

        /** @var array<string, mixed> $section */
        self::assertSame([], QueryCacheConfig::fromArray($section)->unknownConfigKeys());
    }

    /**
     * Every row of the documented table, turned back into the call it describes.
     */
    #[Test]
    public function theDocumentedTruthTableMatchesTheCode(): void
    {
        $markdown = (string) file_get_contents(self::DOCUMENT);
        $rows = DocumentedQueryCacheDefaults::rows($markdown);

        self::assertCount(
            4,
            $rows,
            'the documented table no longer covers all four combinations of preset and written-down '
            . '`enabled`. The combination it drops is the one the next reader will get wrong.',
        );

        $violations = DocumentedQueryCacheDefaults::violations($markdown);

        self::assertSame(
            [],
            $violations,
            "docs/database.md describes a query-cache default the code does not produce:\n  "
            . implode("\n  ", $violations),
        );
    }

    /**
     * The negative half: a table that lies is caught, and a table that has gone
     * missing is refused rather than read as "nothing to check".
     */
    #[Test]
    public function itCatchesADocumentedRowThatTheCodeDoesNotProduce(): void
    {
        $lying = <<<'MARKDOWN'
            | `regulated_preset` | `enabled` written down | Caching |
            | ------------------ | ---------------------- | ------- |
            | `false` (default)  | omitted                | on      |
            | `true`             | omitted                | on      |
            MARKDOWN;

        $violations = DocumentedQueryCacheDefaults::violations($lying);

        self::assertCount(
            1,
            $violations,
            'a documented row claiming caching stays on under the regulated preset was accepted. '
            . 'What ships on that silence is the finding this whole class exists for: a sentence '
            . 'about a safeguard, and no safeguard.',
        );
        self::assertStringContainsString('documented as caching on', $violations[0]);
        self::assertStringContainsString('produces off', $violations[0]);
    }

    #[Test]
    public function itRefusesADocumentWhoseTableHasBeenDeleted(): void
    {
        try {
            DocumentedQueryCacheDefaults::rows("# Query cache\n\nCaching is configurable.\n");
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('no longer carries', $refusal->getMessage());

            return;
        }

        self::fail(
            'a document with no table was read as a document with nothing to disagree about, so '
            . 'deleting the table would have silenced the check instead of failing it.',
        );
    }

    #[Test]
    public function itRefusesARowItCannotTurnIntoAConfiguration(): void
    {
        $unreadable = <<<'MARKDOWN'
            | `regulated_preset` | `enabled` written down | Caching  |
            | ------------------ | ---------------------- | -------- |
            | `true`             | omitted                | probably |
            MARKDOWN;

        try {
            DocumentedQueryCacheDefaults::rows($unreadable);
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('does not say on or off', $refusal->getMessage());

            return;
        }

        self::fail('a row that claims nothing checkable was counted as a row that was checked');
    }
}
