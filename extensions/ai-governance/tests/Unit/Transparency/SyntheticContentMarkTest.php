<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Transparency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentMark;

use function explode;

/**
 * Article 50(2) asks for marking that is machine-readable and detectable, and
 * those two words are what these tests hold the type to.
 *
 * Detectable means a reader that knows nothing about Pulsar can tell the content
 * is artificially generated. That is why the assertion is a leading, unambiguous
 * member rather than something inferable from the presence of a model name.
 *
 * Machine-readable means parseable without bespoke code, which is why the header
 * form is an RFC 8941 structured-field dictionary and why its shape is pinned
 * here: a change that broke the grammar would still look fine to a human reading
 * the string.
 */
#[CoversClass(SyntheticContentMark::class)]
final class SyntheticContentMarkTest extends TestCase
{
    private const int GENERATED_AT = 1_770_000_000;

    #[Test]
    public function theHeaderLeadsWithTheAssertionItself(): void
    {
        $header = $this->mark(SyntheticContentKind::Image)->toHeaderValue();

        // The detection point. A consumer looking for one thing looks for this,
        // and it comes first so a truncated read still carries the claim.
        self::assertStringStartsWith('ai-generated=?1', $header);
    }

    #[Test]
    public function theHeaderCarriesTheCorroboratingDetail(): void
    {
        $header = $this->mark(SyntheticContentKind::Audio)->toHeaderValue();

        self::assertStringContainsString('kind="audio"', $header);
        self::assertStringContainsString('model="acme-tts-3"', $header);
        self::assertStringContainsString('surface="voice-line"', $header);
        self::assertStringContainsString('generated=' . self::GENERATED_AT, $header);
    }

    #[Test]
    public function theHeaderParsesAsAStructuredFieldDictionary(): void
    {
        // Pinned as a grammar rather than as a literal string so the test says
        // what interoperability requires instead of freezing today's field order
        // for its own sake: members are `key=value` pairs separated by ", ".
        $header = $this->mark(SyntheticContentKind::Video)->toHeaderValue();

        foreach (explode(', ', $header) as $member) {
            self::assertMatchesRegularExpression('/^[a-z-]+=("[^"]*"|\?1|\d+)$/', $member, $member);
        }
    }

    #[Test]
    public function theDataFormAssertsGenerationAsATrueValueNotAString(): void
    {
        // A consumer branching on this must not have to know that the string
        // "false" is truthy in most languages.
        $data = $this->mark(SyntheticContentKind::Text)->toArray();

        self::assertTrue($data['ai_generated']);
        self::assertSame('text', $data['kind']);
        self::assertSame(self::GENERATED_AT, $data['generated_at']);
    }

    #[Test]
    public function aMarkNamingNoModelIsRefused(): void
    {
        // An assertion with nothing to check it against is not evidence. The
        // registry exists precisely so the id resolves to a risk classification.
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/must name the model that produced it/');

        new SyntheticContentMark(SyntheticContentKind::Image, 'gallery', '  ', self::GENERATED_AT);
    }

    #[Test]
    public function aMarkNamingNoSurfaceIsRefused(): void
    {
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/must be identified/');

        new SyntheticContentMark(SyntheticContentKind::Image, '', 'acme-diffusion-1', self::GENERATED_AT);
    }

    #[Test]
    public function theGenerationTimeIsTheCallersAndNotAClockReading(): void
    {
        // A marker reading a clock records the time of MARKING and calls it the
        // time of generation. Those differ by however long the pipeline is, and
        // the difference is exactly what an incident timeline turns on.
        $mark = new SyntheticContentMark(
            SyntheticContentKind::Text,
            'article-writer',
            'acme-llm-2',
            1_600_000_000,
        );

        self::assertSame(1_600_000_000, $mark->generatedAt);
    }

    private function mark(SyntheticContentKind $kind): SyntheticContentMark
    {
        $model = match ($kind) {
            SyntheticContentKind::Audio => 'acme-tts-3',
            SyntheticContentKind::Text => 'acme-llm-2',
            default => 'acme-diffusion-1',
        };

        $surface = match ($kind) {
            SyntheticContentKind::Audio => 'voice-line',
            default => 'studio',
        };

        return new SyntheticContentMark($kind, $surface, $model, self::GENERATED_AT);
    }
}
